# TeoNotify - Teo 系列评论通知插件

> **Teo 系列**的评论通知插件（搭配 [TeoSeo](https://github.com/Astarry-1127/TeoSeo) 使用）：适配 **Typecho 1.3**，有新评论/新回复时自动通过邮件通知博主或评论者。

## 功能

| 能力 | 说明 |
| --- | --- |
| **新评论通知博主** | 文章收到新评论时，邮件通知博主（评论内容 + 文章链接） |
| **回复通知评论者** | 博主回复评论时，邮件通知评论者"您的评论已回复，请访问文章页查看" |
| **可扩展通道** | 通知通道抽象为 `ChannelInterface`，当前实现 SMTP 邮件；后续可扩展 Server酱 / 钉钉 / Webhook 等，只需新增一个 `Channels/<Name>Channel.php` |
| **纯 PHP 实现** | SMTP 客户端用原生 `stream_socket_client` 实现，无第三方依赖，适配虚拟主机 |

## 安装

1. 将 `TeoNotify` 文件夹上传到 `usr/plugins/`
2. 后台启用插件
3. 在插件设置中填写 SMTP 配置：
   - SMTP 服务器（如 `smtp.qiye.aliyun.com`）
   - SMTP 端口（SSL 465 / STARTTLS 587）
   - SMTP 用户名 / 密码
   - 发件人地址
   - 博主接收邮箱（留空则用站点管理员邮箱）

## 使用说明

- 通知范围可选：新评论通知博主 / 博主回复时通知评论者（可多选）
- 博主本人登录发表的评论不触发通知
- 评论者需填写邮箱才会收到回复通知（Typecho 评论默认要求填邮箱）

## 通道扩展示例

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

    public function sendReplyNotice(array $parent, array $reply, array $context): void
    {
        // 同上
    }
}
```

放入 `Channels/ServerChanChannel.php` 后，插件自动发现并启用（需在设置中补充对应配置项）。

## 作者

- 作者：Astarry
- 博客：https://blog.astarry.cn
- 反馈：GitHub Issues

## License

GNU General Public License 2.0
