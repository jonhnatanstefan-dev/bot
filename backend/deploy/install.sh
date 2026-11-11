#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo "Execute com sudo"; exit 1; }
BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
command -v python3 >/dev/null || { echo "Python 3 não instalado"; exit 1; }
command -v systemctl >/dev/null || { echo "systemd indisponível"; exit 1; }
id isp-ia >/dev/null 2>&1 || useradd -r -d /var/lib/isp-ia -s /usr/sbin/nologin isp-ia
install -d -o root -g root -m 755 /opt/isp-ia
install -d -o isp-ia -g isp-ia -m 750 /var/lib/isp-ia
install -d -o root -g isp-ia -m 750 /etc/isp-ia
install -o root -g root -m 644 "$BASE/backend/app.py" /opt/isp-ia/app.py
if [[ ! -f /etc/isp-ia/assistant.env ]]; then
    TOKEN="$(python3 -c 'import secrets; print(secrets.token_urlsafe(36))')"
    cat > /etc/isp-ia/assistant.env <<EOF
IA_HOST=127.0.0.1
IA_PORT=8787
IA_MODEL=qwen3:1.7b
IA_DB_PATH=/var/lib/isp-ia/chat.db
IA_OLLAMA_URL=http://127.0.0.1:11434/api/chat
IA_ADMIN_TOKEN=$TOKEN
EOF
fi
chown root:isp-ia /etc/isp-ia/assistant.env
chmod 640 /etc/isp-ia/assistant.env
cat >/etc/systemd/system/isp-ia.service <<'EOF'
[Unit]
Description=Assistente IA NET+ SPPDNET (laboratorio; sem WhatsApp)
After=network-online.target ollama.service
Wants=network-online.target
[Service]
Type=simple
User=isp-ia
Group=isp-ia
EnvironmentFile=/etc/isp-ia/assistant.env
WorkingDirectory=/opt/isp-ia
ExecStart=/usr/bin/python3 /opt/isp-ia/app.py
Restart=on-failure
RestartSec=4
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/var/lib/isp-ia
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
UMask=0077
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now isp-ia
systemctl restart isp-ia
sleep 1
curl --fail --silent --show-error http://127.0.0.1:8787/health
echo
echo "Instalação backend concluída. Token fica APENAS em /etc/isp-ia/assistant.env"
echo "Consulte localmente: sudo grep '^IA_ADMIN_TOKEN=' /etc/isp-ia/assistant.env"
