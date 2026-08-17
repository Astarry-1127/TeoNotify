#!/usr/bin/env python3
"""QQ 机器人闭环监听 (TeoNotify server 端)

流程:
1. 收到 C2C 消息(用户引用某条 QQ 通知回复)
2. 从被引用消息内容(msg_elements)中提取"评论ID=xxx"
3. 用用户回复文本作为内容
4. 生成 HMAC 签名, POST 到博客安全接口发布博主回复
5. 博客侧安全接口再邮件通知原评论者

配置(与 qq-server.py 相同):
  环境变量: QQ_APPID / QQ_APPSECRET / QQ_BLOG_API / QQ_BLOG_KEY
  或同目录 config.json
依赖: pip3 install websockets
"""
import asyncio
import hashlib
import hmac
import json
import os
import re
import sys
import time
import urllib.request
import urllib.error
import websockets

# ---------- 配置 ----------
def _load_config():
    cfg = {}
    cfg_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json")
    if os.path.exists(cfg_path):
        try:
            cfg = json.load(open(cfg_path))
        except Exception as e:
            print(f"[warn] config.json 解析失败: {e}", file=sys.stderr)
    cfg["appid"] = os.environ.get("QQ_APPID") or cfg.get("appid", "")
    cfg["appsecret"] = os.environ.get("QQ_APPSECRET") or cfg.get("appsecret", "")
    cfg["blog_api"] = os.environ.get("QQ_BLOG_API") or cfg.get("blog_api", "")
    cfg["blog_key"] = os.environ.get("QQ_BLOG_KEY") or cfg.get("blog_key", "")
    return cfg

CONF = _load_config()
APPID = CONF.get("appid", "")
APPSECRET = CONF.get("appsecret", "")
BLOG_API = CONF.get("blog_api", "")   # 博客安全接口地址
BLOG_KEY = CONF.get("blog_key", "")   # 博客安全接口 key

TOKEN_URL = "https://api.bot.qq.com/app/getAppAccessToken"
GATEWAY_URL = "https://api.sgroup.qq.com/gateway"
INTENTS_C2C = (1 << 25)  # GROUP_AND_C2C_EVENT

_token = None
_token_expire = 0


def fetch_token():
    data = json.dumps({"appId": APPID, "clientSecret": APPSECRET}).encode()
    req = urllib.request.Request(TOKEN_URL, data=data,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=15) as resp:
        return json.loads(resp.read().decode())["access_token"]


def get_token():
    global _token, _token_expire
    now = time.time()
    if _token and now < _token_expire - 120:
        return _token
    _token = fetch_token()
    _token_expire = now + 7000
    return _token


def fetch_gateway(token):
    req = urllib.request.Request(GATEWAY_URL,
                                 headers={"Authorization": "QQBot " + token})
    with urllib.request.urlopen(req, timeout=15) as resp:
        return json.loads(resp.read().decode())["url"]


# ============ 评论处理 ============
COMMENT_ID_RE = re.compile(r"评论ID=(\d+)")


def extract_comment_id(ref_content):
    m = COMMENT_ID_RE.search(ref_content or "")
    return int(m.group(1)) if m else None


def publish_comment(cid, content):
    """生成 HMAC 签名, POST 到博客安全接口"""
    ts = str(int(time.time()))
    nonce = f"{int(time.time()*1000)}{cid}"
    msg = f"{ts}|{nonce}|{cid}|{content}"
    sign = hmac.new(BLOG_KEY.encode(), msg.encode(), hashlib.sha256).hexdigest()

    payload = json.dumps({"cid": cid, "content": content}, ensure_ascii=False).encode()
    req = urllib.request.Request(
        BLOG_API, data=payload,
        headers={
            "Content-Type": "application/json",
            "User-Agent": "Mozilla/5.0 (TeoNotify/1.0)",
            "X-Notify-Key": BLOG_KEY,
            "X-Notify-Ts": ts,
            "X-Notify-Nonce": nonce,
            "X-Notify-Sign": sign,
        })
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        return {"ok": False, "error": f"HTTP {e.code}: {e.read().decode()[:200]}"}
    except Exception as e:
        return {"ok": False, "error": str(e)}


def handle_message(msg):
    d = msg
    openid = d.get("author", {}).get("user_openid") or d.get("user_openid")

    content = d.get("content")
    if isinstance(content, list):
        content = "".join(it.get("text", "") for it in content if isinstance(it, dict))

    # 被引用的消息内容(msg_elements)
    ref_content = None
    for el in (d.get("msg_elements") or []):
        if isinstance(el, dict) and el.get("content"):
            ref_content = el.get("content")
            break

    print(f"[C2C] 收到 openid={openid} msg='{content}'", flush=True)
    if not ref_content:
        print("[C2C] 未带引用, 跳过", flush=True)
        return

    cid = extract_comment_id(ref_content)
    print(f"[C2C] 引用内容: '{ref_content}' → 评论ID={cid}", flush=True)
    if not cid:
        print("[C2C] 引用内容不含评论ID, 跳过", flush=True)
        return

    result = publish_comment(cid, content)
    print(f"[C2C] 发布评论结果: {result}", flush=True)


# ============ WebSocket ============
async def gateway_client():
    token = get_token()
    url = fetch_gateway(token)
    print(f"[ws] 连接: {url}", flush=True)
    seq = None
    while True:
        try:
            async with websockets.connect(url, ping_interval=None) as ws:
                print("[ws] 已连接", flush=True)
                async for raw in ws:
                    msg = json.loads(raw)
                    op = msg.get("op")
                    if op == 10:
                        asyncio.create_task(heartbeat(ws, lambda: seq))
                        await ws.send(json.dumps({
                            "op": 2, "d": {
                                "token": "QQBot " + token, "intents": INTENTS_C2C,
                                "shard": [0, 1],
                                "properties": {"$os": "linux", "$browser": "teonotify", "$device": "server"},
                            }}))
                    elif op == 0:
                        seq = msg.get("s")
                        t = msg.get("t")
                        if t == "C2C_MESSAGE_CREATE":
                            handle_message(msg.get("d") or {})
                        elif t == "READY":
                            print("[ws] READY", flush=True)
        except Exception as e:
            print(f"[ws] 异常: {e}, 5s重连", flush=True)
            await asyncio.sleep(5)


async def heartbeat(ws, seq_getter):
    while True:
        await asyncio.sleep(30)
        try:
            await ws.send(json.dumps({"op": 1, "d": seq_getter()}))
        except Exception:
            break


def main():
    if not APPID or not APPSECRET:
        print("[错误] 未配置 QQ_APPID/QQ_APPSECRET", file=sys.stderr)
        sys.exit(1)
    get_token()
    print("[qq] 启动引用回复评论闭环", flush=True)
    asyncio.run(gateway_client())


if __name__ == "__main__":
    main()
