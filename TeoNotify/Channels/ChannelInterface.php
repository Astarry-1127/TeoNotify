<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 通知通道接口
 *
 * 所有通知通道(邮件/Server酱/Webhook 等)实现本接口。
 * 新增通道只需在 Channels/ 下新建 <Name>Channel.php 并实现本接口,
 * 插件会自动发现并加载。
 */
interface TeoNotify_ChannelInterface
{
    /**
     * 通道是否已配置启用
     */
    public function isEnabled(): bool;

    /**
     * 新评论通知博主
     *
     * @param array $comment 评论数据
     * @param array $context 上下文(含 article/permalink)
     */
    public function sendNewCommentNotice(array $comment, array $context): void;

    /**
     * 博主回复时通知评论者
     *
     * @param array $parent 被回复的评论
     * @param array $reply  新的回复评论
     * @param array $context 上下文(含 article/permalink)
     */
    public function sendReplyNotice(array $parent, array $reply, array $context): void;
}
