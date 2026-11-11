#!/usr/bin/env python3
"""API local do laboratório NET+/SPPDNET. Sem WhatsApp e sem ações em clientes."""
import argparse
import hmac
import json
import logging
import os
import re
import sqlite3
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

COMPANIES = ("NET+", "SPPDNET")
SESSIONS = re.compile(r"^[A-Za-z0-9_.-]{1,64}$")
DB = Path(os.getenv("IA_DB_PATH", "/var/lib/isp-ia/chat.db"))
HOST = os.getenv("IA_HOST", "127.0.0.1")
PORT = int(os.getenv("IA_PORT", "8787"))
TOKEN = os.getenv("IA_ADMIN_TOKEN", "")
MODEL = os.getenv("IA_MODEL", "qwen3:1.7b")
OLLAMA = os.getenv("IA_OLLAMA_URL", "http://127.0.0.1:11434/api/chat")
LOCK = threading.Lock()
LOG = logging.getLogger("isp-ia")
POLICY = """Você é o assistente da empresa {company}, um provedor de internet brasileiro.
Atue apenas em português do Brasil e responda em até 70 palavras.
Este é um laboratório SIMULADO. Você não possui acesso a clientes, rede, pagamentos nem WhatsApp.
Nunca diga que consultou conexão, dados, saldo, faturas, pagamentos, suporte, chamados ou equipamentos.
Não invente valores, prazos, causas de falhas ou informações de clientes.
Quando o cliente estiver sem internet, pergunte se o problema ocorre em todos os aparelhos.
Faça uma pergunta objetiva de cada vez; nunca solicite senhas, documentos completos ou dados bancários.
Solicitações financeiras e mudanças na rede exigem atendente humano.
O nome da empresa neste atendimento é {company}. Não misture dados ou empresas.
"""

def validate(company, session):
    if company not in COMPANIES:
        raise ValueError("Empresa inválida")
    if not isinstance(session, str) or not SESSIONS.fullmatch(session):
        raise ValueError("Sessão inválida")
    return company, session

def conn():
    DB.parent.mkdir(parents=True, exist_ok=True, mode=0o750)
    db = sqlite3.connect(str(DB), timeout=20)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA busy_timeout=10000")
    db.execute("PRAGMA journal_mode=WAL")
    return db

def init_db():
    with conn() as db:
        db.execute("""CREATE TABLE IF NOT EXISTS messages (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          company TEXT NOT NULL CHECK(company IN ('NET+', 'SPPDNET')),
          session TEXT NOT NULL,
          role TEXT NOT NULL CHECK(role IN ('user', 'assistant')),
          content TEXT NOT NULL,
          created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )""")
        db.execute("CREATE INDEX IF NOT EXISTS idx_chat ON messages(company,session,id)")

def history(company, session, limit=16):
    validate(company, session)
    with conn() as db:
        rows = db.execute("""SELECT role, content, created_at FROM (
          SELECT id,role,content,created_at FROM messages
          WHERE company=? AND session=? ORDER BY id DESC LIMIT ?
        ) ORDER BY id ASC""", (company, session, limit)).fetchall()
        return [dict(x) for x in rows]

def ask_model(company, previous, message):
    payload = {
        "model": MODEL, "stream": False, "think": False,
        "messages": ([{"role":"system", "content":POLICY.format(company=company)}]
                    + [{"role":x["role"],"content":x["content"]} for x in previous]
                    + [{"role":"user","content":message}]),
        "options":{"temperature":0.2, "num_predict":150, "num_ctx":2048},
    }
    request = urllib.request.Request(
        OLLAMA, data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type":"application/json"}, method="POST"
    )
    with urllib.request.urlopen(request, timeout=150) as response:
        result = json.load(response)
    reply = result.get("message", {}).get("content", "").strip()
    if not reply:
        raise ValueError("IA retornou resposta vazia")
    return reply[:1800]

def chat(company, session, message):
    validate(company, session)
    if not isinstance(message, str) or not 1 <= len(message.strip()) <= 2000:
        raise ValueError("Mensagem deve conter entre 1 e 2000 caracteres")
    begin = time.monotonic()
    with LOCK:
        previous = history(company, session)
        answer = ask_model(company, previous, message.strip())
        with conn() as db:
            db.execute("INSERT INTO messages(company,session,role,content) VALUES(?,?,?,?)",
                       (company, session, "user", message.strip()))
            db.execute("INSERT INTO messages(company,session,role,content) VALUES(?,?,?,?)",
                       (company, session, "assistant", answer))
    return {
        "company": company, "session": session, "reply": answer,
        "model": MODEL, "elapsed_s": round(time.monotonic()-begin, 2),
        "sent_to_whatsapp": False,
    }

class Handler(BaseHTTPRequestHandler):
    server_version = "ISP-IA-Lab/0.2"
    def respond(self, status, data):
        output = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(output)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(output)

    def authorized(self):
        return len(TOKEN) >= 32 and hmac.compare_digest(
            self.headers.get("Authorization", ""), "Bearer " + TOKEN
        )

    def do_GET(self):
        uri = urlsplit(self.path)
        if uri.path == "/health":
            return self.respond(200, {"status":"ok", "mode":"simulation", "model":MODEL})
        if not self.authorized():
            return self.respond(401, {"error":"Não autorizado"})
        if uri.path == "/api/history":
            args = parse_qs(uri.query)
            try:
                company = args.get("company", [None])[0]
                session = args.get("session", [None])[0]
                return self.respond(200, {"company":company,"session":session,
                                          "messages":history(company,session)})
            except ValueError as exc:
                return self.respond(400, {"error":str(exc)})
        return self.respond(404, {"error":"Não encontrado"})

    def do_POST(self):
        if not self.authorized():
            return self.respond(401, {"error":"Não autorizado"})
        if urlsplit(self.path).path != "/api/chat":
            return self.respond(404, {"error":"Não encontrado"})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if not 1 <= length <= 8000:
                return self.respond(413, {"error":"Requisição muito grande ou vazia"})
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            if not isinstance(payload, dict):
                raise ValueError("Objeto JSON esperado")
            return self.respond(200, chat(payload.get("company"),
                                          payload.get("session"),
                                          payload.get("message")))
        except (ValueError, UnicodeError, json.JSONDecodeError) as exc:
            return self.respond(400, {"error":str(exc)})
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            LOG.warning("Falha Ollama: %s", type(exc).__name__)
            return self.respond(503, {"error":"IA local indisponível"})
        except Exception:
            LOG.exception("Erro interno")
            return self.respond(500, {"error":"Erro interno"})

    def log_message(self, format, *args):
        LOG.info("%s %s", self.client_address[0], format % args)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--init-db", action="store_true")
    opts = parser.parse_args()
    init_db()
    if opts.init_db:
        print("Banco inicializado", DB)
        return
    if len(TOKEN) < 32:
        raise SystemExit("IA_ADMIN_TOKEN precisa ter ao menos 32 caracteres")
    if HOST not in ("127.0.0.1", "localhost", "::1"):
        raise SystemExit("A API de laboratório só pode escutar em loopback")
    logging.basicConfig(level=logging.INFO)
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    LOG.info("API local em %s:%s, sem WhatsApp", HOST, PORT)
    server.serve_forever()

if __name__ == "__main__":
    main()
