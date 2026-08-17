# TeoNotify 服务端 (QQ 遥控回复评论)

本目录是 TeoNotify 进阶功能（QQ 通知 + 引用回复遥控评论）所需的**常驻服务端**。

## 前提

- 一台**常驻在线的服务器**（VPS / 云服务器 / 家庭小主机，需能跑 Python3）
- **QQ 机器人**（[QQ 开放平台](https://q.qq.com) 注册，个人主体即可，拿 AppID/AppSecret）
- Python3 + `websockets` 库

## 文件说明

| 文件 | 作用 | 部署位置 |
| --- | --- | --- |
| `qq-server.py` | QQ token 管理 + HTTP 发消息服务 | 服务器 |
| `qq-ws-listener.py` | WebSocket 监听(收引用回复→自动发评论) | 服务器 |
| `teonotify-qq-comment.php` | 博客侧安全评论接口 | 博客站点根目录 |
| `config.example.json` | 配置模板 | 服务器(复制为 config.json) |

## 部署步骤

### 1. 服务器端

```bash
pip3 install websockets

# 复制配置模板并填写(不要提交 config.json 到 Git)
cp config.example.json config.json
vim config.json
```

`config.json` 填写：
```json
{
  "appid": "你的QQ机器人AppID",
  "appsecret": "你的QQ机器人AppSecret",
  "api_key": "自定义随机串(HTTP服务鉴权, 博客端也用同一个)",
  "blog_api": "https://你的博客域名/teonotify-qq-comment.php",
  "blog_key": "与博客安全接口一致的Key"
}
```

启动两个服务：
```bash
python3 qq-server.py          # HTTP 服务(默认端口 8970)
python3 qq-ws-listener.py     # WebSocket 监听
```

> 建议用 systemd / supervisord 守护，保持常驻。示例 systemd：
> ```
> [Unit]
> Description=TeoNotify QQ server
> After=network.target
> [Service]
> ExecStart=/usr/bin/python3 /opt/teonotify/qq-server.py
> Restart=always
> [Install]
> WantedBy=multi-user.target
> ```

### 2. 博客端

1. 将 `teonotify-qq-comment.php` 上传到博客站点根目录
2. 在根目录创建并配置 `teo_notify_config.json`（**不要提交 Git**）：
```json
{
  "api_key": "与服务器 config.json 的 api_key 一致",
  "allow_ips": ["你的服务器公网IP"],
  "smtp": {
    "host": "smtp.qiye.aliyun.com",
    "port": 465,
    "user": "admin@example.cn",
    "pass": "你的SMTP授权码",
    "from": "admin@example.cn"
  }
}
```
> SMTP 若省略，会回退读取 TeoNotify 插件后台配置的 SMTP。

### 3. 获取 openid

你的 QQ 给机器人发任意一条消息，`qq-ws-listener.py` 会从事件中捕获你的 `user_openid`，并打印到日志。

### 4. TeoNotify 后台配置

填写：
- `常驻服务地址`：`http://你的服务器IP:8970`
- `服务鉴权 Key`：与 config.json 的 `api_key` 一致
- `接收者 openid`：上一步获取的 openid

---

## 验证闭环

1. 访客在博客评论 → 手机 QQ 收到 `【新评论】评论ID=xx ...` 通知
2. **引用该通知回复**一条消息
3. 博客文章评论页出现你的**博主回复**，且评论者收到回复邮件

---

## 安全

博客侧 `teonotify-qq-comment.php` 多重校验：
- IP 白名单（REMOTE_ADDR，不可伪造）
- `X-Notify-Key` API Key
- HMAC-SHA256 签名（`ts|nonce|cid|content`）
- 时间戳 + nonce 防重放
- 内容长度/标签过滤 + cid 存在性校验

**务必**：不要提交 `config.json` / `teo_notify_config.json` 到任何公开仓库。
