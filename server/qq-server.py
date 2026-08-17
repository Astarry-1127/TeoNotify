#!/usr/bin/env python3
"""QQ 机器人消息服务 (TeoNotify server 端)

功能:
1. access_token 管理: AppID+AppSecret 换取, 2小时有效, 自动刷新
2. HTTP 接口向指定 openid 主动发送单聊消息

部署:
  pip3 install websockets
  配置方式二选一:
    A. 环境变量: QQ_APPID / QQ_APPSECRET / QQ_API_KEY
    B. 同目录 config.json: {"appid","appsecret","api_key"}
  python3 qq-server.py

接口:
  POST /send   body {"openid":"xxx","content":"msg"} + X-API-Key 头
  GET  /health /token
"""
import json
import os
import sys
import time
import threading
import urllib.request
import urllib.parse

# ---------- 配置(从环境变量或 config.json) ----------
def _load_config():
    cfg = {}
    # 1. 同目录 config.json
    cfg_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json")
    if os.path.exists(cfg_path):
        try:
            cfg = json.load(open(cfg_path))
        except Exception as e:
            print(f"[warn] config.json 解析失败: {e}", file=sys.stderr)
    # 2. 环境变量优先覆盖
    cfg["appid"] = os.environ.get("QQ_APPID") or cfg.get("appid", "")
    cfg["appsecret"] = os.environ.get("QQ_APPSECRET") or cfg.get("appsecret", "")
    cfg["api_key"] = os.environ.get("QQ_API_KEY") or cfg.get("api_key", "")
    return cfg

CONF = _load_config()
APPID = CONF.get("appid", "")
APPSECRET = CONF.get("appsecret", "")
API_KEY = CONF.get("api_key", "")

TOKEN_URL = "https://api.bot.qq.com/app/getAppAccessToken"
SEND_URL = "https://api.sgroup.qq.com/v2/users/{openid}/messages"
ACCESS_TOKEN_TTL = 7200  # 秒


class TokenManager:
    """access_token 管理: 缓存 + 快到期的自动刷新"""
    def __init__(self):
        self.token = None
        self.expire_at = 0
        self.lock = threading.Lock()

    def get(self, force=False):
        with self.lock:
            if not force and self.token and time.time() < self.expire_at - 120:
                return self.token
            token, ttl = self._fetch()
            self.token = token
            self.expire_at = time.time() + int(ttl)
            return self.token

    def _fetch(self):
        data = json.dumps({"appId": APPID, "clientSecret": APPSECRET}).encode()
        req = urllib.request.Request(TOKEN_URL, data=data,
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=15) as resp:
            body = json.loads(resp.read().decode())
        if "access_token" not in body:
            raise RuntimeError(f"获取 token 失败: {body}")
        print(f"[token] 已获取, ttl={body.get('expires_in')}s", flush=True)
        return body["access_token"], body.get("expires_in", ACCESS_TOKEN_TTL)


token_mgr = TokenManager()


def send_message(openid, content):
    """向指定 openid 发送主动单聊消息"""
    token = token_mgr.get()
    url = SEND_URL.format(openid=urllib.parse.quote(openid))
    payload = json.dumps({
        "content": content,
        "msg_type": 0,  # 文本
    }).encode()
    req = urllib.request.Request(
        url, data=payload,
        headers={"Authorization": "QQBot " + token,
                 "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=15) as resp:
        return json.loads(resp.read().decode())


# ---------- HTTP 服务 ----------
from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def _check_auth(self):
        return self.headers.get("X-API-Key") == API_KEY

    def do_POST(self):
        if not self._check_auth():
            self._resp(401, {"ok": False, "error": "unauthorized"})
            return
        if self.path.startswith("/send"):
            length = int(self.headers.get("Content-Length", 0))
            body = json.loads(self.rfile.read(length).decode() or "{}")
            openid = body.get("openid", "")
            content = body.get("content", "")
            if not openid or not content:
                self._resp(400, {"ok": False, "error": "openid/content 必填"})
                return
            try:
                for attempt in (1, 2):
                    try:
                        result = send_message(openid, content)
                        self._resp(200, {"ok": True, "result": result})
                        return
                    except urllib.error.HTTPError as e:
                        code = e.code
                        err = e.read().decode()
                        if code in (401, 403) and attempt == 1:
                            token_mgr.get(force=True)
                            continue
                        self._resp(code, {"ok": False, "error": err})
                        return
            except Exception as ex:
                self._resp(500, {"ok": False, "error": str(ex)})
            return
        self._resp(404, {"ok": False, "error": "not found"})

    def do_GET(self):
        if self.path.startswith("/token"):
            if not self._check_auth():
                self._resp(401, {"ok": False, "error": "unauthorized"})
                return
            self._resp(200, {"ok": True, "token": token_mgr.get()})
            return
        if self.path.startswith("/health"):
            self._resp(200, {"ok": True, "status": "up"})
            return
        self._resp(404, {"ok": False, "error": "not found"})

    def _resp(self, code, obj):
        data = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, fmt, *args):
        print(f"[http] {self.client_address[0]} {fmt % args}", flush=True)


def main():
    if not APPID or not APPSECRET:
        print("[错误] 未配置 QQ_APPID/QQ_APPSECRET(环境变量) 或 config.json", file=sys.stderr)
        sys.exit(1)
    port = int(os.environ.get("QQ_SERVER_PORT", "8970"))
    srv = HTTPServer(("0.0.0.0", port), Handler)
    print(f"[qqbot] QQ 机器人服务启动: http://0.0.0.0:{port}", flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
