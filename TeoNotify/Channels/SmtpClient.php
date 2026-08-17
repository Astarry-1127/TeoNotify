<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoNotify_SmtpClient - 极简 SMTP 客户端
 *
 * 用 PHP 原生 stream_socket_client 实现 SMTP 协议发送,
 * 支持 SSL(465) 与 STARTTLS(587) 加密, 支持 base64 认证。
 * 无第三方依赖, 适配虚拟主机环境。
 */
class TeoNotify_SmtpClient
{
    private $host;
    private $port;
    private $user;
    private $pass;
    private $from;
    private $fromName;

    /** @var resource */
    private $conn;
    private $debug = false;

    public function __construct($host, $port, $user, $pass, $from, $fromName)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->from = $from;
        $this->fromName = $fromName;
    }

    public function setDebug($on = true)
    {
        $this->debug = $on;
    }

    /**
     * 发送邮件
     *
     * @throws Exception
     */
    public function send($to, $subject, $html)
    {
        // SSL 连接(465)或普通连接后 STARTTLS(587)
        $remote = 'tcp://' . $this->host . ':' . $this->port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        if ($this->port === 465) {
            $remote = 'ssl://' . $this->host . ':' . $this->port;
        }

        $this->conn = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        if (!$this->conn) {
            throw new \Exception("SMTP 连接失败: $errstr ($errno)");
        }
        stream_set_timeout($this->conn, 30);

        $this->expect(220);

        $this->ehlo();

        if ($this->port !== 465) {
            // STARTTLS
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \Exception('STARTTLS 协商失败');
            }
            $this->ehlo();
        }

        // AUTH LOGIN
        $this->cmd('AUTH LOGIN', 334);
        $this->cmd(base64_encode($this->user), 334);
        $this->cmd(base64_encode($this->pass), 235);

        // 邮件头与正文
        $this->cmd('MAIL FROM:<' . $this->from . '>', 250);
        $this->cmd('RCPT TO:<' . $to . '>', 250);
        $this->cmd('DATA', 354);

        $headers = $this->buildHeaders($to, $subject);
        $body = $this->buildBody($html);

        $this->write($headers . $body . "\r\n.\r\n");
        $this->expect(250);

        $this->cmd('QUIT', 221);
        fclose($this->conn);
    }

    /**
     * EHLO: 读取完整多行响应(以 "250 " 结尾才结束)
     */
    private function ehlo()
    {
        $this->cmd('EHLO ' . (php_uname('n') ?: 'localhost'));
        // 多行响应: 连续读行, 直到一行不以 '-' 结尾
        for (;;) {
            $line = $this->readline();
            if ($line === false || strlen($line) < 4) {
                break;
            }
            if ($line[3] !== '-') {
                break; // 最后一行 (如 "250 OK")
            }
        }
    }

    /**
     * 构建邮件头
     */
    private function buildHeaders($to, $subject): string
    {
        $headers = [];
        $headers[] = 'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->from . '>';
        $headers[] = 'To: ' . $to;
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'X-Mailer: TeoNotify/1.0';
        return implode("\r\n", $headers) . "\r\n\r\n";
    }

    /**
     * RFC2047 编码含中文的邮件头
     */
    private function encodeHeader($str): string
    {
        if (preg_match('/[^\x20-\x7e]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }

    /**
     * 正文 base64 编码(每行76字符)
     */
    private function buildBody($html): string
    {
        return chunk_split(base64_encode($html));
    }

    /**
     * 发送原始命令
     */
    private function cmd($cmd, $expectCode = null)
    {
        $this->write($cmd . "\r\n");
        if ($expectCode !== null) {
            $code = $this->expect($expectCode);
            return $code;
        }
        return null;
    }

    private function write($data)
    {
        if ($this->debug) {
            error_log('[TeoNotify SMTP] C: ' . trim($data));
        }
        fwrite($this->conn, $data);
    }

    private function readline()
    {
        $line = fgets($this->conn);
        if ($this->debug) {
            error_log('[TeoNotify SMTP] S: ' . trim($line));
        }
        return $line;
    }

    /**
     * 期望响应码
     *
     * @throws Exception
     */
    private function expect($code)
    {
        $line = $this->readline();
        if ($line === false || (int) substr($line, 0, 3) !== $code) {
            throw new \Exception("SMTP 预期响应 $code, 实际: " . trim($line));
        }
        return (int) substr($line, 0, 3);
    }
}
