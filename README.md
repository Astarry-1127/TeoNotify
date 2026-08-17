# TeoNotify - Teo 系列评论通知插件

> **Teo 系列**的 Typecho 评论通知插件（搭配 [TeoSeo](https://github.com/Astarry-1127/TeoSeo) 使用）。
> 适配 **Typecho 1.3**，在 `vincent了吗` 的留言基础之上，做成了"**邮件通知 + QQ 遥控回复评论**"的双通道方案。

---

## ✨ 核心卖点

**TeoNotify 不只是通知，还能让你"用 QQ 遥控博客回复评论"**：

```
访客评论 → QQ 通知(带评论ID) → 你引用通知回复
    → 自动发布博主回复到博客 + 邮件通知评论者
```

你在外面，用手机 QQ 引用机器人发的通知回复一条，博客评论就和你回复了——**极致的移动端评论管理体验**。

## 功能总览

| 能力 | 说明 |
| --- | --- |
| **邮件通知博主** | 新评论时邮件通知博主（美化 HTML + 文章链接） |
| **邮件通知评论者** | 博主回复时，邮件通知评论者"您的评论已回复"（Outlook 兼容按钮） |
| **QQ 通知** | 新评论/回复时通过 QQ 机器人主动推送到博主手机 |
| **QQ 遥控回复** | 在 QQ 引用通知消息回复，自动发布为博主对评论的回复 |
| **多点防垃圾** | 评论发布接口含 IP 白名单 + API Key + HMAC 签名 + 防重放 |
| **纯 PHP / 纯 Python** | SMTP 用原生 `stream_socket_client`，无第三方依赖，适配虚拟主机 |
| **可扩展通道** | 通知通道抽象为 `ChannelInterface`，可扩展 Server酱 / 钉钉 / Webhook 等 |

---

## 📦 项目结构

```
TeoNotify/         Typecho 插件本体
  Plugin.php       主插件(钩子/配置面板)
  Channels/        通知通道
    ChannelInterface.php
    MailChannel.php     邮件通道(纯 PHP SMTP)
    QQChannel.php       QQ 机器人通道
    SmtpClient.php      极简 SMTP 客户端
server/           进阶功能所需的服务端代码(部署在常驻服务器)
  qq-server.py          QQ token 管理 + HTTP 发消息服务
  qq-ws-listener.py     WebSocket 监听(QQ 引用回复 → 自动发布评论)
  teonotify-qq-comment.php  博客侧安全评论接口
  config.example.json   服务端配置模板
```

---

## 🚀 快速开始（邮件通道，人人可用）

### 1. 安装插件
1. 将 `TeoNotify` 文件夹上传到 `usr/plugins/`
2. Typecho 后台启用插件

### 2. 配置邮件 SMTP
后台插件设置中填写：
- SMTP 服务器（如 `smtp.qiye.aliyun.com`）
- SMTP 端口（SSL 465 / STARTTLS 587）
- SMTP 用户名 / 密码（推荐用带授权码的企业邮箱）
- 发件人地址
- **博主接收邮箱**（新评论通知发往此邮箱）

### 3. 完成
- 有人评论 → 邮件通知你（发件人是你配的 SMTP）
- 你在后台回复评论 → 邮件通知评论者

---

## 🤖 进阶：QQ 遥控回复评论

需要 **QQ 机器人** + **一台常驻服务器**（VPS / 云服务器 / 家用小主机皆可）。

### 原理架构
```
[博客] TeoNotify 触发
  ├─ 邮件通知博主
  └─ 调 server 的 /send → QQ 机器人 → 通知你QQ(带评论ID)
                                              ↑ 你引用回复
[server] WebSocket 监听 → 识别评论ID → HMAC签名
  └─ POST 博客安全接口 → 发布博主回复 → 邮件通知评论者
```

### 步骤
1. **注册 QQ 机器人**：[QQ 开放平台](https://q.qq.com) → 注册（个人主体即可）→ 创建机器人 → 拿到 `AppID` / `AppSecret`
2. **部署服务端**：将 `server/` 内容部署到你的服务器，参考 [server/README.md](server/README.md)
3. **配置插件 QQ 通道**：后台填 `qq_server_url`（服务地址）、`qq_api_key`、`qq_openid`，勾选启用
4. **获取 openid**：你的 QQ 先给机器人发一条消息，WebSocket 会自动捕获你的 openid
5. **修复评论接口**：上传 `server/teonotify-qq-comment.php` 到博客站目录，并配置 `teo_notify_config.json`

> 🎁 **半托管选项**：如果不想自己搭服务器，可联系作者获取作者的常驻服务器。（注意！由于需要使用自己的机器人，所以考虑清楚自己是否有这个需求再联系作者。）

---

## 🔧 通道扩展示例

新增一个通知通道（如 Server酱）：

```php
class TeoNotify_ServerChanChannel implements TeoNotify_ChannelInterface
{
    public function __construct(array $settings) { /* 读取自己的配置 */ }
    public function isEnabled(): bool { return !empty($this->settings['sckey']); }
    public function sendNewCommentNotice(array $comment, array $context): void
    {
        // 通过 GET https://sctapi.ftqq.com/<sckey>.send?title=...&desp=... 推送
    }
    public function sendReplyNotice(array $parent, array $reply, array $context): void {}
}
```

放入 `Channels/ServerChanChannel.php` 后，插件自动发现并启用。

---

## 📝 使用说明

- 通知范围可选：新评论通知博主 / 博主回复时通知评论者（可多选）
- 博主本人登录发表的评论不触发通知
- 评论者需填写邮箱才会收到回复通知（Typecho 评论默认要求填邮箱）
- QQ 通知格式带 `评论ID=xxx`，用于引用回复闭环识别

---

## 🛡️ 安全设计

博客侧评论接收接口（`teonotify-qq-comment.php`）采用多层校验：
1. **IP 白名单**：仅接受服务器公网 IP（基于 REMOTE_ADDR，不可伪造）
2. **API Key**：请求头 `X-Notify-Key` 校验
3. **HMAC-SHA256 签名**：`sign = HMAC(key, ts|nonce|cid|content)` 防伪造
4. **时间戳 + nonce**：防重放攻击
5. **内容约束**：长度限制 + 危险标签过滤 + cid 存在性校验

---

## 版本历史

- **v1.0.5**：新增 QQ 频道（通知 + 引用回复遥控评论），安全接口多层防垃圾，邮件美化（Outlook 兼容按钮），服务端代码开源
- **v1.0.0**：邮件通道基础版

---

## 作者

- 作者：Astarry
- 博客：https://blog.astarry.cn
- 反馈：[GitHub Issues](https://github.com/Astarry-1127/TeoNotify/issues)

## License

GNU General Public License 2.0
