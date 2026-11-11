import importlib.util
import json
import tempfile
import threading
import unittest
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer
from pathlib import Path

BACKEND = Path(__file__).resolve().parents[1] / "backend" / "app.py"
spec = importlib.util.spec_from_file_location("isp_backend", BACKEND)
api = importlib.util.module_from_spec(spec)
spec.loader.exec_module(api)


class LocalAssistantTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        api.DB = Path(self.temp.name) / "test.sqlite3"
        api.init_db()
        self.original = api.ask_model
        api.ask_model = lambda company, previous, message: "Atendimento de " + company + ": posso ajudar?"
        api.TOKEN = "a" * 48

    def tearDown(self):
        api.ask_model = self.original
        self.temp.cleanup()

    def test_separate_companies(self):
        api.chat("NET+", "teste", "Cliente da Net")
        self.assertEqual(len(api.history("NET+", "teste")), 2)
        self.assertEqual(api.history("SPPDNET", "teste"), [])

    def test_separate_sessions(self):
        api.chat("SPPDNET", "a", "Conexão A")
        self.assertEqual(api.history("SPPDNET", "b"), [])

    def test_invalid_tenant_and_session(self):
        with self.assertRaises(ValueError):
            api.chat("OUTRA", "teste", "Olá")
        with self.assertRaises(ValueError):
            api.chat("NET+", "../../arquivo", "Olá")

    def test_policy_does_not_pretend_access(self):
        self.assertIn("SIMULADO", api.POLICY)
        self.assertIn("Nunca diga que consultou", api.POLICY)

    def test_http_authentication(self):
        server = ThreadingHTTPServer(("127.0.0.1", 0), api.Handler)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        url = "http://127.0.0.1:" + str(server.server_port)
        try:
            with urllib.request.urlopen(url + "/health", timeout=3) as response:
                self.assertEqual(json.load(response)["status"], "ok")
            with self.assertRaises(urllib.error.HTTPError) as failure:
                urllib.request.urlopen(url + "/api/history?company=NET%2B&session=teste", timeout=3)
            self.assertEqual(failure.exception.code, 401)
            req = urllib.request.Request(url + "/api/history?company=NET%2B&session=teste",
                                         headers={"Authorization": "Bearer " + api.TOKEN})
            with urllib.request.urlopen(req, timeout=3) as response:
                self.assertEqual(json.load(response)["messages"], [])
        finally:
            server.shutdown()
            server.server_close()


if __name__ == "__main__":
    unittest.main()
