<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * MailChannel - SMTP 邮件通知通道
 *
 * 纯 PHP 实现 SMTP 客户端(stream_socket_client), 不依赖第三方库,
 * 适配西部数码等虚拟主机环境。支持 SSL/TLS 加密发送。
 * 未配置 SMTP 时通道自动禁用,不影响其他通道。
 */
class TeoNotify_MailChannel implements TeoNotify_ChannelInterface
{
    /** 插件配置 */
    private $settings;

    /** 站点名称 */
    private $siteName;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $options = Typecho_Widget::widget('Widget_Options');
        $this->siteName = $options->title ?? '我的博客';
    }

    public function isEnabled(): bool
    {
        return !empty($this->settings['mail_host'])
            && !empty($this->settings['mail_user'])
            && !empty($this->settings['mail_pass']);
    }

    public function sendNewCommentNotice(array $comment, array $context): void
    {
        $to = $this->adminMail();
        if (!$to) {
            return;
        }
        $subject = sprintf('[%s] 新评论: %s', $this->siteName, $this->commentPreview($comment));
        $body = $this->render('您收到一条新评论', $comment, $context, false);
        $this->send($to, $subject, $body);
    }

    public function sendReplyNotice(array $parent, array $reply, array $context): void
    {
        $to = $parent['mail'] ?? '';
        if (!$to) {
            return;
        }
        $subject = sprintf('[%s] 您的评论已回复', $this->siteName);
        $body = $this->render('您的评论已回复', $reply, $context, true);
        $this->send($to, $subject, $body);
    }

    /**
     * 博主接收邮箱
     */
    private function adminMail(): string
    {
        if (!empty($this->settings['admin_mail'])) {
            return $this->settings['admin_mail'];
        }
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('mail')->from('table.users')
            ->where('group = ?', 'administrator')->limit(1));
        return $row['mail'] ?? '';
    }

    /**
     * 评论内容预览(截断)
     */
    private function commentPreview(array $comment): string
    {
        $text = trim(strip_tags($comment['text'] ?? ''));
        return mb_substr($text, 0, 30, 'utf-8') . (mb_strlen($text) > 30 ? '…' : '');
    }

    /**
     * 渲染通知正文(HTML + 纯文本双版本)
     */
    private function render(string $title, array $comment, array $context, bool $isReply): string
    {
        $author = $comment['author'] ?? '匿名';
        $text = trim(strip_tags($comment['text'] ?? ''));
        $permalink = $context['permalink'] ?? '#';
        $articleTitle = $context['article']['title'] ?? '文章';

        $html = <<<HTML
<html><body style="font-family:sans-serif;font-size:14px;color:#333;line-height:1.7">
  <h2>{$title}</h2>
  <p><strong>{$author}</strong> 评论了文章 <a href="{$permalink}">《{$articleTitle}》</a>:</p>
  <blockquote style="border-left:3px solid #ccc;padding-left:12px;color:#555">{$text}</blockquote>
  <p><a href="{$permalink}" style="background:#2b7de9;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px">查看文章</a></p>
  <hr style="border:none;border-top:1px solid #eee">
  <p style="color:#999;font-size:12px">本邮件由 {$this->siteName} 评论通知插件自动发送</p>
</body></html>
HTML;
        return $html;
    }

    /**
     * 发送邮件: 纯 PHP SMTP 客户端
     */
    private function send(string $to, string $subject, string $html): void
    {
        if (!class_exists('TeoNotify_SmtpClient', false)) {
            require_once __DIR__ . '/SmtpClient.php';
        }
        $host = $this->settings['mail_host'];
        $port = (int) ($this->settings['mail_port'] ?? 465);
        $user = $this->settings['mail_user'];
        $pass = $this->settings['mail_pass'];
        $from = $this->from();

        $smtp = new TeoNotify_SmtpClient($host, $port, $user, $pass, $from, $this->siteName);
        $smtp->send($to, $subject, $html);
    }

    private function from(): string
    {
        return !empty($this->settings['mail_from'])
            ? $this->settings['mail_from']
            : $this->settings['mail_user'];
    }
}
