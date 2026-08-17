<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

// 兼容层: Typecho 1.3 的插件接口为命名空间版 Typecho\Plugin\PluginInterface,
// 后台 parseInfo 依赖 `implements Typecho_Plugin_Interface` 识别插件类。
if (!interface_exists('Typecho_Plugin_Interface', false)) {
    interface Typecho_Plugin_Interface
    {
    }
}

/**
 * TeoNotify - Typecho 评论通知插件
 *
 * 1. 有新评论时通过邮件通知博主(评论内容 + 文章链接)
 * 2. 博主回复评论时,邮件通知评论者("您的评论已回复,请访问文章页查看")
 *
 * 通知通道采用可扩展设计: 当前实现 MailChannel(SMTP 邮件),
 * 后续可新增 ServerChanChannel / WebhookChannel 等,只需实现 ChannelInterface。
 *
 * @package TeoNotify
 * @author Astarry
 * @version 1.0.0
 * @link https://blog.astarry.cn
 * @license GNU General Public License 2.0
 */
class TeoNotify_Plugin implements Typecho_Plugin_Interface
{
    /** 通知通道命名空间(相对插件目录) */
    const CHANNELS_DIR = 'Channels';

    /**
     * 插件激活: 挂载评论完成钩子
     */
    public static function activate(): string
    {
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = ['TeoNotify_Plugin', 'onComment'];
        return '评论通知插件已激活';
    }

    /**
     * 插件停用: 移除钩子
     */
    public static function deactivate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = null;
    }

    /**
     * 插件配置面板
     *
     * @param Typecho_Widget_Helper_Form $form 配置表单
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $enabled = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'notify_scope',
            ['new' => '新评论通知博主', 'reply' => '博主回复时通知评论者'],
            ['new'],
            _t('通知范围'),
            _t('选择要触发的通知类型')
        );
        $form->addInput($enabled);

        $mailHost = new Typecho_Widget_Helper_Form_Element_Text('mail_host', null, '', _t('SMTP 服务器'), _t('如 smtp.qiye.aliyun.com'));
        $form->addInput($mailHost);

        $mailPort = new Typecho_Widget_Helper_Form_Element_Text('mail_port', null, '465', _t('SMTP 端口'), _t('SSL 通常 465, 非加密 25'));
        $form->addInput($mailPort);

        $mailUser = new Typecho_Widget_Helper_Form_Element_Text('mail_user', null, '', _t('SMTP 用户名'), _t('发件邮箱, 如 admin@astarry.cn'));
        $form->addInput($mailUser);

        $mailPass = new Typecho_Widget_Helper_Form_Element_Password('mail_pass', null, '', _t('SMTP 密码'), _t('邮箱登录密码或授权码'));
        $form->addInput($mailPass);

        $mailFrom = new Typecho_Widget_Helper_Form_Element_Text('mail_from', null, '', _t('发件人地址'), _t('通常与 SMTP 用户名一致'));
        $form->addInput($mailFrom);

        $adminMail = new Typecho_Widget_Helper_Form_Element_Text('admin_mail', null, '', _t('博主接收邮箱'), _t('新评论通知发往该邮箱, 留空则读取站点管理员邮箱'));
        $form->addInput($adminMail);
    }

    /**
     * 保存配置
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 评论完成钩子处理
     *
     * @param Widget_Feedback $feedback 评论组件实例
     */
    public static function onComment($feedback)
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $settings = self::settings();

            // 读取刚插入的评论
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select()->from('table.comments')
                ->where('coid = ?', $feedback->coid));

            if (!$row) {
                return;
            }

            $scope = $settings['notify_scope'] ?? ['new'];
            $isReply = !empty($row['parent']);
            $isApproved = $row['status'] === 'approved';

            // 博主本人评论不通知(避免自说自话)
            if ($row['authorId'] > 0 && $feedback->user->hasLogin()) {
                return;
            }

            $article = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ?', $row['cid']));

            // 构建通知
            $channels = self::channels();
            $context = [
                'comment'  => $row,
                'article'  => $article,
                'permalink' => self::permalink($row['cid']),
            ];

            if ($isReply && in_array('reply', $scope)) {
                // 回复评论者
                $parent = $db->fetchRow($db->select()->from('table.comments')
                    ->where('coid = ?', $row['parent']));
                if ($parent && !empty($parent['mail'])) {
                    foreach ($channels as $ch) {
                        $ch->sendReplyNotice($parent, $row, $context);
                    }
                }
                // 回复也同时通知博主(回复新内容)
                if (in_array('new', $scope)) {
                    foreach ($channels as $ch) {
                        $ch->sendNewCommentNotice($row, $context);
                    }
                }
            } elseif (in_array('new', $scope)) {
                // 新评论通知博主
                foreach ($channels as $ch) {
                    $ch->sendNewCommentNotice($row, $context);
                }
            }
        } catch (\Exception $e) {
            // 通知失败不影响评论本身
            error_log('[TeoNotify] ' . $e->getMessage());
        }
    }

    /**
     * 读取插件配置
     */
    private static function settings(): array
    {
        $opt = Typecho_Widget::widget('Widget_Options')->plugin('TeoNotify');
        $data = [];
        foreach (['notify_scope', 'mail_host', 'mail_port', 'mail_user', 'mail_pass', 'mail_from', 'admin_mail'] as $k) {
            $data[$k] = $opt->{$k} ?? '';
        }
        return $data;
    }

    /**
     * 实例化所有启用的通知通道
     *
     * @return array TeoNotify_ChannelInterface[]
     */
    private static function channels(): array
    {
        $dir = __DIR__ . '/' . self::CHANNELS_DIR;
        $channels = [];
        if (!is_dir($dir)) {
            return $channels;
        }
        // 先加载通道接口
        $iface = $dir . '/ChannelInterface.php';
        if (is_file($iface) && !interface_exists('TeoNotify_ChannelInterface', false)) {
            require_once $iface;
        }
        foreach (glob($dir . '/*Channel.php') as $file) {
            $class = 'TeoNotify_' . basename($file, '.php');
            if (!class_exists($class)) {
                require_once $file;
            }
            $ch = new $class(self::settings());
            if ($ch->isEnabled()) {
                $channels[] = $ch;
            }
        }
        return $channels;
    }

    /**
     * 文章链接
     */
    private static function permalink($cid): string
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $router = Typecho_Router::get('post');
        if ($router) {
            $db = Typecho_Db::get();
            $article = $db->fetchRow($db->select('slug, created')->from('table.contents')
                ->where('cid = ?', $cid));
            if ($article) {
                return Typecho_Common::url(
                    Typecho_Router::url('post', $article),
                    $options->siteUrl
                );
            }
        }
        return $options->siteUrl;
    }
}
