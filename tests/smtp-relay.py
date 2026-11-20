"""Loopback TLS SMTP fixture. It accepts synthetic example.test recipients only."""
import json
import pathlib
import socketserver
import ssl
import sys

CERT, KEY, READY, JOURNAL = map(pathlib.Path, sys.argv[1:])


class Relay(socketserver.TCPServer):
    allow_reuse_address = True
    attempts = {}

    def get_request(self):
        client, address = super().get_request()
        client.settimeout(10)
        return context.wrap_socket(client, server_side=True), address


class Session(socketserver.StreamRequestHandler):
    def reply(self, line):
        self.wfile.write((line + "\r\n").encode("ascii"))

    def handle(self):
        self.reply("220 localhost test relay")
        recipient = ""
        while True:
            raw = self.rfile.readline(4096)
            if not raw:
                return
            line = raw.decode("ascii", errors="replace").strip()
            command = line.partition(" ")[0].upper()
            if command in ("EHLO", "HELO"):
                self.reply("250 localhost")
            elif command in ("MAIL", "RSET"):
                recipient = ""
                self.reply("250 OK")
            elif command == "RCPT":
                recipient = line.partition("<")[2].partition(">")[0].lower()
                self.reply("250 OK" if recipient.endswith("@example.test") else "550 Synthetic recipients only")
            elif command == "DATA" and recipient.endswith("@example.test"):
                self.reply("354 Enter message")
                body = []
                while True:
                    part = self.rfile.readline(4096)
                    if not part:
                        return
                    if part == b".\r\n":
                        break
                    body.append(part)
                attempt = self.server.attempts.get(recipient, 0) + 1
                self.server.attempts[recipient] = attempt
                response = (
                    "451 Try again" if recipient.startswith("retry@") and attempt == 1
                    else "550 Rejected" if recipient.startswith("reject@")
                    else "disconnect" if recipient.startswith("uncertain@")
                    else "250 Accepted"
                )
                with JOURNAL.open("a", encoding="utf-8") as handle:
                    handle.write(json.dumps({"recipient": recipient, "result": response,
                                             "message": b"".join(body).decode("utf-8", errors="replace")}) + "\n")
                if response == "disconnect":
                    return
                self.reply(response)
            elif command == "QUIT":
                self.reply("221 Bye")
                return
            else:
                self.reply("500 Unknown command")


context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(CERT, KEY)
with Relay(("127.0.0.1", 0), Session) as server:
    READY.write_text(str(server.server_address[1]), encoding="ascii")
    server.serve_forever()
