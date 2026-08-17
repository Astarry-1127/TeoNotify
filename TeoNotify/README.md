# TeoNotify - Teo 系列评论通知插件

> **Teo 系列**的评论通知插件，推荐搭配作者另一款插件 [TeoSeo](https://github.com/Astarry-1127/TeoSeo) 使用：适配 **Typecho 1.3**，邮件通知 + QQ 遥控回复评论。

## 功能

| 能力 | 说明 |
| --- | --- |
| **新评论通知博主** | 邮件通知博主（美化 HTML + 链接） |
| **回复通知评论者** | 博主回复时邮件通知评论者（Outlook 兼容按钮） |
| **QQ 通知** | 通过 QQ 机器人主动推送通知到手机 |
| **QQ 遥控回复** | 引用 QQ 通知回复 → 自动发布为博主回复评论 |
| **可扩展通道** | `ChannelInterface` 抽象，可扩展 Server酱 / 钉钉 / Webhook |
| **纯 PHP** | SMTP 用原生 `stream_socket_client`，无第三方依赖，适配虚拟主机 |

## 安装

1. 将 `TeoNotify` 文件夹上传到 `usr/plugins/`
2. 后台启用插件
3. 配置 SMTP（既有邮件通道）+ 可选配置 QQ 通道

## 邮件通道（基础）

后台填写：
- SMTP 服务器 / 端口 / 用户名 / 密码 / 发件人
- 博主接收邮箱

- 有人评论 → 通知你
- 你回复 → 邮件通知评论者

## QQ 通道（进阶）

需要 QQ 机器人 + 常驻服务器，详见仓库根 `server/README.md`。

后台填写：
- `常驻服务地址`
- `服务鉴权 Key`
- `接收者 openid`

勾选"启用 QQ 机器人通知"。

## 通道扩展示例

```php
class TeoNotify_ServerChanChannel implements TeoNotify_ChannelInterface
{
    public function __construct(array $settings) {}
    public function isEnabled(): bool { return !empty($this->settings['sckey']); }
    public function sendNewCommentNotice(array $comment, array $context): void {}
    public function sendReplyNotice(array $parent, array $reply, array $context): void {}
}
```

## 版本

- v1.0.5：QQ 频道 + 遥控回复 + 安全接口 + 邮件美化

## 作者

- Astarry · https://blog.astarry.cn

## License

GNU General Public License 2.0
