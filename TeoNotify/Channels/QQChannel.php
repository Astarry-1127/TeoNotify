<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * QQChannel - QQ 机器人通知通道(半托管/自建)
 *
 * 通过腾讯 QQ 机器人官方 openapi 主动向指定 openid 发送单聊消息。
 * 调用方式: POST 到「常驻服务」的 /send 接口(由用户自建或半托管在 Astarry 服务器)。
 *
 * 配置项(后台):
 *   qq_enabled       是否启用
 *   qq_server_url    常驻服务地址, 如 http://47.76.140.103:8970
 *   qq_api_key       常驻服务鉴权 key
 *   qq_openid        接收消息的 openid
 */
class TeoNotify_QQChannel implements TeoNotify_ChannelInterface
{
    private $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function isEnabled(): bool
    {
        $on = !empty($this->settings['qq_enabled']);
        return $on
            && !empty($this->settings['qq_openid'])
            && !empty($this->settings['qq_server_url'])
            && !empty($this->settings['qq_api_key']);
    }

    public function sendNewCommentNotice(array $comment, array $context): void
    {
        $text = $this->formatComment($comment, $context);
        $this->push($text);
    }

    public function sendReplyNotice(array $parent, array $reply, array $context): void
    {
        // 回复评论者(对方可能不是加了好友的openid, 无法主动推送, 跳过)
        // QQ 主动私聊需要对方先加机器人为好友; 评论者邮箱不可推断 openid, 故只通知博主
        $text = $this->formatReply($reply, $context);
        $this->push($text);
    }

    /**
     * 新评论消息
     */
    private function formatComment(array $comment, array $context): string
    {
        $author = $comment['author'] ?? '匿名';
        $text = trim(strip_tags($comment['text'] ?? ''));
        $permalink = $context['permalink'] ?? '';
        $article = $context['article']['title'] ?? '文章';
        $coid = $comment['coid'] ?? 0;
        // 带 评论ID= 标记, 供引用回复闭环识别
        return "【新评论】评论ID={$coid} 《{$article}》\n{$author}: {$text}\n回复可直接引用本消息" . ($permalink ? "\n{$permalink}" : '');
    }

    /**
     * 回复消息(发给博主)
     */
    private function formatReply(array $reply, array $context): string
    {
        $text = trim(strip_tags($reply['text'] ?? ''));
        $permalink = $context['permalink'] ?? '';
        return "【回复已发出】\n{$text}\n" . $permalink;
    }

    /**
     * 调常驻服务发送
     */
    private function push(string $content): void
    {
        $url = rtrim($this->settings['qq_server_url'], '/') . '/send';
        $payload = json_encode([
            'openid'  => $this->settings['qq_openid'],
            'content' => $content,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->settings['qq_api_key'],
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === false || $code >= 400) {
            error_log('[TeoNotify QQ] 发送失败 code=' . $code . ' resp=' . substr((string)$resp, 0, 200));
        }
    }
}
