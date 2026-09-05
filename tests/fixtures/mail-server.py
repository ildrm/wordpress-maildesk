"""Local-only IMAP/SMTP fixtures. No messages leave this process."""
import base64
import json
import os
import re
import socketserver
import threading
from pathlib import Path

ROOT = Path(os.environ.get("WPMD_FIXTURE_DIR", "/private/tmp/wpmd-mail-fixtures"))
ROOT.mkdir(parents=True, exist_ok=True)
LOCK = threading.Lock()
FLAGS = {7: [], 8: ["\\Seen"]}
MAILBOXES = {name: {uid: list(flags) for uid, flags in FLAGS.items()} for name in ["INBOX", "Sent", "&ZeVnLIqe-"]}
RAW = ("From: \"Doe, Jane\" <jane@example.test>\r\nTo: reviewer@example.test\r\n"
       "Reply-To: replies@example.test\r\nSubject: =?UTF-8?B?SGVsbG8g4pyT?=\r\n"
       "Message-ID: <fixture@example.test>\r\nDate: Sat, 05 Sep 2026 05:00:00 +0000\r\n"
       "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=outer\r\n\r\n"
       "--outer\r\nContent-Type: multipart/alternative; boundary=inner\r\n\r\n"
       "--inner\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
       "Hello =E2=9C=93\r\nThis is the complete message.\r\n"
       "--inner\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
       "<h1>Hello &#10003;</h1><p>This is the complete message.</p><img src=\"https://tracking.invalid/pixel\"><p style=\"background:url(https://tracking.invalid/css)\">Safe reader</p>\r\n"
       "--inner--\r\n--outer\r\nContent-Type: text/plain; name=notes.txt\r\n"
       "Content-Disposition: attachment; filename=notes.txt\r\nContent-Transfer-Encoding: base64\r\n\r\n"
       + base64.b64encode(b"Fixture attachment content\n").decode() + "\r\n--outer--\r\n").encode()
(ROOT / "message.eml").write_bytes(RAW)

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

class Imap(socketserver.StreamRequestHandler):
    def send(self, value):
        self.wfile.write(value.encode() if isinstance(value, str) else value)
        self.wfile.flush()
    def handle(self):
        global MAILBOXES
        self.mailbox = "INBOX"
        self.send("* OK MailDesk fixture\r\n")
        while raw := self.rfile.readline():
            text = raw.decode().rstrip("\r\n")
            if " " not in text: break
            tag, command = text.split(" ", 1)
            upper = command.upper()
            if upper == "XTESTRESET":
                with LOCK: MAILBOXES = {name: {uid: list(flags) for uid, flags in FLAGS.items()} for name in ["INBOX", "Sent", "&ZeVnLIqe-"]}
            elif upper.startswith("LOGIN "): pass
            elif upper == "AUTHENTICATE XOAUTH2":
                self.send("+ \r\n"); self.rfile.readline()
            elif upper == "CAPABILITY": self.send("* CAPABILITY IMAP4rev1 MOVE AUTH=PLAIN AUTH=XOAUTH2\r\n")
            elif upper.startswith("LIST "):
                self.send('* LIST (\\HasNoChildren) "/" "INBOX"\r\n')
                self.send('* LIST (\\Noselect) "/" "Parent"\r\n')
                self.send('* LIST (\\Sent) "/" "Sent"\r\n')
                self.send('* LIST () "/" "&ZeVnLIqe-"\r\n')
            elif upper.startswith(("SELECT ", "EXAMINE ")):
                self.mailbox = json.loads(command.split(" ", 1)[1])
                self.send("* 2 EXISTS\r\n* OK [UIDVALIDITY 42] Valid\r\n* OK [UIDNEXT 9] Next\r\n")
            elif upper.startswith("UID SEARCH"):
                self.send("* SEARCH " + " ".join(str(uid) for uid in sorted(MAILBOXES[self.mailbox])) + "\r\n")
            elif upper.startswith("UID FETCH"):
                uid = int(command.split()[2])
                if uid in MAILBOXES[self.mailbox]:
                    flags = " ".join(MAILBOXES[self.mailbox][uid])
                    prefix = f'* {uid - 6} FETCH (FLAGS ({flags}) RFC822.SIZE {len(RAW)} INTERNALDATE "05-Sep-2026 05:00:00 +0000" '
                    if "BODY.PEEK[" in upper:
                        self.send(prefix + f"BODY[] {{{len(RAW)}}}\r\n")
                        self.send(RAW)
                        self.send(f" UID {uid})\r\n")
                    else: self.send(prefix + f"UID {uid})\r\n")
            elif upper.startswith("UID STORE"):
                uid = int(command.split()[2])
                flag = re.search(r"\(([^)]+)\)", command).group(1)
                with LOCK:
                    flags = MAILBOXES[self.mailbox][uid]
                    if "+FLAGS" in upper and flag not in flags: flags.append(flag)
                    if "-FLAGS" in upper and flag in flags: flags.remove(flag)
            elif upper.startswith("UID MOVE"):
                uid = int(command.split()[2]); target = json.loads(command.split(" ", 3)[3])
                with LOCK:
                    new_uid = max(MAILBOXES[target], default=0) + 1
                    MAILBOXES[target][new_uid] = MAILBOXES[self.mailbox].pop(uid)
            elif upper == "LOGOUT":
                self.send("* BYE\r\n" + tag + " OK done\r\n"); break
            else:
                self.send(tag + " BAD unsupported fixture command\r\n"); continue
            self.send(tag + " OK done\r\n")

class Smtp(socketserver.StreamRequestHandler):
    def send(self, value):
        self.wfile.write(value.encode()); self.wfile.flush()
    def handle(self):
        self.send("220 localhost fixture\r\n")
        recipients = []
        while raw := self.rfile.readline():
            line = raw.decode(errors="replace").strip(); upper = line.upper()
            if upper.startswith(("EHLO", "HELO")): self.send("250-localhost\r\n250-AUTH PLAIN LOGIN XOAUTH2\r\n250 SIZE 20000000\r\n")
            elif upper.startswith("AUTH XOAUTH2"): self.send("235 authenticated\r\n")
            elif upper.startswith("AUTH PLAIN"): self.send("235 authenticated\r\n")
            elif upper.startswith("AUTH LOGIN"):
                self.send("334 VXNlcm5hbWU6\r\n"); self.rfile.readline(); self.send("334 UGFzc3dvcmQ6\r\n"); self.rfile.readline(); self.send("235 authenticated\r\n")
            elif upper.startswith("MAIL FROM"): recipients = []; self.send("250 sender accepted\r\n")
            elif upper.startswith("RCPT TO"):
                if "reject@" in line: self.send("550 rejected fixture recipient\r\n")
                else: recipients.append(line); self.send("250 recipient accepted\r\n")
            elif upper == "DATA":
                self.send("354 end with dot\r\n"); body = b""
                while part := self.rfile.readline():
                    if part == b".\r\n": break
                    body += part
                with LOCK:
                    with (ROOT / "deliveries.jsonl").open("a") as f: f.write(json.dumps({"recipients": recipients, "body": body.decode(errors="replace")}) + "\n")
                if any("drop@" in r for r in recipients): return
                self.send("250 accepted fixture message\r\n")
            elif upper == "QUIT": self.send("221 goodbye\r\n"); return
            else: self.send("250 OK\r\n")

imap = Server(("127.0.0.1", 11430), Imap)
smtp = Server(("127.0.0.1", 11025), Smtp)
threading.Thread(target=imap.serve_forever, daemon=True).start()
print("MailDesk fixtures on localhost IMAP 11430 / SMTP 11025", flush=True)
smtp.serve_forever()
