<?php
/**
 * TeoNotify - QQ 回复评论接收接口
 *
 * 接收来自 hk2 的"QQ回复评论"请求, 插入博主回复评论, 并邮件通知原评论者。
 * 安全验证(分层):
 *   1. IP 白名单(REMOTE_ADDR, 不可伪造)
 *   2. API Key 请求头
 *   3. HMAC-SHA256 签名(ts+nonce+cid+content) 防伪造
 *   4. ts + nonce 防重放
 *   5. cid 存在性 + 内容约束
 *
 * POST /teonotify-qq-comment.php
 *   headers: X-Notify-Key, X-Notify-Sign, X-Notify-Ts, X-Notify-Nonce
 *   body: {"cid":123,"content":"回复内容"}
 */
$raw = @file_get_contents(dirname(__FILE__) . '/config.inc.php');
function grab($raw, $key) {
    if (preg_match("/'{$key}'\s*=>\s*'([^']*)'/", $raw, $m)) return $m[1];
    if (preg_match("/'{$key}'\s*=>\s*(\d+)/", $raw, $m)) return $m[1];
    return '';
}
$c = array(
    'host' => grab($raw, 'host'), 'port' => grab($raw, 'port'),
    'user' => grab($raw, 'user'), 'pass' => grab($raw, 'password'),
    'db'   => grab($raw, 'database'),
);
$PREFIX  = 'typecho_';
$TOLERANCE_TS = 300;

// 敏感配置从同目录 teo_notify_config.json 读取(不入库, 不提交真实值到 Git)
$_notify_cfg = array();
$_cfg_file = dirname(__FILE__) . '/teo_notify_config.json';
if (is_file($_cfg_file)) {
    $_notify_cfg = json_decode(file_get_contents($_cfg_file), true) ?: array();
}
$NOTIFY_KEY  = $_notify_cfg['api_key'] ?? 'CHANGE_ME';
$ALLOW_IPS   = isset($_notify_cfg['allow_ips']) ? (array)$_notify_cfg['allow_ips'] : array('127.0.0.1');

function fail($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}
function okk($data = array()) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('ok' => true), $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. IP 白名单
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($clientIp, $ALLOW_IPS)) fail(403, 'ip not allowed: ' . $clientIp);

// 2. API Key
if (!hash_equals($NOTIFY_KEY, $_SERVER['HTTP_X_NOTIFY_KEY'] ?? '')) fail(401, 'invalid key');

// 3. Body
$body = json_decode(file_get_contents('php://input'), true);
$cid     = intval($body['cid'] ?? 0);
$content = trim($body['content'] ?? '');
if ($cid <= 0) fail(400, 'cid required');
if ($content === '') fail(400, 'content required');

// 4. ts/nonce/sign
$ts    = $_SERVER['HTTP_X_NOTIFY_TS'] ?? '';
$nonce = $_SERVER['HTTP_X_NOTIFY_NONCE'] ?? '';
$sign  = $_SERVER['HTTP_X_NOTIFY_SIGN'] ?? '';
if (!$ts || abs(time() - intval($ts)) > $TOLERANCE_TS) fail(401, 'ts invalid');
if (!$nonce || strlen($nonce) < 8) fail(400, 'nonce invalid');
$expected = hash_hmac('sha256', "{$ts}|{$nonce}|{$cid}|{$content}", $NOTIFY_KEY);
if (!hash_equals($expected, $sign)) fail(401, 'sign invalid');

// 5. 内容约束
if (mb_strlen($content) > 500) fail(400, 'content too long');
if (preg_match('/<\s*(script|iframe|object|embed|style)/i', $content)) fail(400, 'forbidden tags');

// 6. 数据库
$db = new mysqli($c['host'], $c['user'], $c['pass'], $c['db'], intval($c['port']));
if ($db->connect_errno) fail(500, 'db connect fail');
$db->set_charset('utf8mb4');

$row = $db->query("SELECT coid,cid FROM {$PREFIX}comments WHERE coid=" . intval($cid))->fetch_assoc();
if (!$row) fail(404, 'comment not found');
$articleCid = intval($row['cid']);

// 文章信息(slug/title) + 站点 URL, 用于构造回复通知链接
$articleSlug = '';
$articleTitle = '文章';
$siteUrl = '';
$ar = $db->query("SELECT slug,title FROM {$PREFIX}contents WHERE cid=$articleCid");
if ($ar && $arow = $ar->fetch_assoc()) {
    $articleSlug = $arow['slug'];
    if (!empty($arow['title'])) $articleTitle = $arow['title'];
}
$sr = $db->query("SELECT value FROM {$PREFIX}options WHERE name='siteUrl'");
if ($sr && $srow = $sr->fetch_assoc()) $siteUrl = $srow['value'];
if (!$siteUrl) $siteUrl = 'https://www.astarry.top/';
$articleUrl = rtrim($siteUrl, '/') . ($articleSlug ? '/' . $articleSlug . '/' : '');
if (!$articleSlug) $articleUrl = rtrim($siteUrl, '/') . '/index.php/archives/' . $articleCid . '/';

//原评论者信息(用于回复通知)
$parentInfo = array('mail' => '', 'author' => '');
$pr = $db->query("SELECT mail,author FROM {$PREFIX}comments WHERE coid=" . intval($cid));
if ($pr && $prow = $pr->fetch_assoc()) {
    $parentInfo = $prow;
}

// 7. 插入博主回复评论
$tsNow = time();
$r = $db->real_escape_string($content);
$a = $db->real_escape_string('博主Astarry');
$m = $db->real_escape_string('admin@astarry.cn');
$sql = "INSERT INTO {$PREFIX}comments (cid,created,author,ownerId,mail,url,ip,agent,text,type,status,parent)
        VALUES ($articleCid, $tsNow, '$a', 1, '$m', '', '$clientIp', 'TeoNotify/QQ', '$r', 'comment', 'approved', " . intval($cid) . ")";
if (!$db->query($sql)) fail(500, 'insert fail: ' . $db->error);
$newId = $db->insert_id;

// 8. 邮件通知原评论者(发件 admin@astarry.cn, 收件原评论者邮箱)
$mailSent = false;
if (!empty($parentInfo['mail'])) {
    // SmtpClient 有 __TYPECHO_ROOT_DIR__ 守卫, 独立脚本先定义
    if (!defined('__TYPECHO_ROOT_DIR__')) {
        define('__TYPECHO_ROOT_DIR__', dirname(__FILE__));
    }
    require_once dirname(__FILE__) . '/usr/plugins/TeoNotify/Channels/SmtpClient.php';
    try {
        // 读取 SMTP 配置: 优先 teo_notify_config.json, 回退 TeoNotify 插件配置
        $smtpCfg = array('host' => 'smtp.qiye.aliyun.com', 'port' => 465, 'user' => '', 'pass' => '', 'from' => '');
        if (!empty($_notify_cfg['smtp'])) {
            $smtpCfg = array_merge($smtpCfg, $_notify_cfg['smtp']);
            $smtpCfg['port'] = intval($smtpCfg['port'] ?: 465);
        }
        if (empty($smtpCfg['user']) || empty($smtpCfg['pass'])) {
            $opt = $db->query("SELECT value FROM {$PREFIX}options WHERE name='plugin:TeoNotify'");
            if ($opt && $orow = $opt->fetch_assoc()) {
                $os = json_decode($orow['value'], true);
                if (!empty($os['mail_host'])) {
                    $smtpCfg = array(
                        'host' => $os['mail_host'], 'port' => intval($os['mail_port'] ?: 465),
                        'user' => $os['mail_user'], 'pass' => $os['mail_pass'],
                        'from' => $os['mail_from'] ?: $os['mail_user'],
                    );
                }
            }
        }
        if (empty($smtpCfg['pass'])) {
            error_log('[TeoNotify] SMTP 未配置, 无法发送回复通知');
        } else {
        // 站点名
        $siteName = 'Astarry技术日记';
        $st = $db->query("SELECT value FROM {$PREFIX}options WHERE name='title'");
        if ($st && $srow = $st->fetch_assoc()) $siteName = $srow['value'];

        $to = $parentInfo['mail'];
        $subject = "[{$siteName}] 您的评论已回复";
        $replyText = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        $artTitle = htmlspecialchars($articleTitle, ENT_QUOTES, 'UTF-8');
        $html = "<html><body style='margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'PingFang SC','Microsoft YaHei',sans-serif'>"
              . "<div style='max-width:560px;margin:20px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06)'>"
              . "<div style='background-color:#2b7de9;padding:22px 28px'><div style='color:#ffffff;font-size:20px;font-weight:600'>您在 {$siteName} 的评论已回复</div></div>"
              . "<div style='padding:24px 28px;color:#333;font-size:15px;line-height:1.7'>"
              . "<p style='margin:0 0 6px'>您好，您在 <strong style='color:#2b7de9'>{$siteName}</strong> 文章《{$artTitle}》的评论收到了新的回复：</p>"
              . "<div style='background:#f7f9fc;border-left:4px solid #2b7de9;border-radius:6px;padding:14px 16px;margin:14px 0;color:#444'>"
              . "<p style='margin:0 0 4px;font-size:13px;color:#888'><strong>博主回复：</strong></p><p style='margin:0'>{$replyText}</p></div>"
              . "<p style='margin:0 0 18px'>请前往博客查看完整回复内容：</p>"
              . "<div style='text-align:center;margin:22px 0'>"
              . "<table role='presentation' cellpadding='0' cellspacing='0' border='0' align='center' style='border-collapse:collapse'><tr><td align='center' bgcolor='#2b7de9' style='border-radius:8px'><a href='{$articleUrl}' style='display:inline-block;background-color:#2b7de9;color:#ffffff !important;padding:13px 32px;font-size:15px;font-weight:bold;text-decoration:none;border-radius:8px'><font color='#ffffff'>前往查看文章</font></a></td></tr></table>"
              . "</div>"
              . "<p style='margin:16px 0 0;font-size:12px;color:#aaa;text-align:center'>此邮件由 {$siteName} TeoNotify 自动发送，请勿直接回复</p>"
              . "</div></div></body></html>";

        $smtp = new TeoNotify_SmtpClient($smtpCfg['host'], $smtpCfg['port'], $smtpCfg['user'], $smtpCfg['pass'], $smtpCfg['from'], $siteName);
        $smtp->send($to, $subject, $html);
        $mailSent = true;
        }
    } catch (Exception $e) {
        error_log('[TeoNotify] reply mail fail: ' . $e->getMessage());
    }
}

$db->close();

okk(array('new_coid' => $newId, 'parent_cid' => $cid, 'mail_notified' => $mailSent, 'reply_to' => $parentInfo['mail']));
