<?php
declare(strict_types=1);

/**
 * Sends the game's emails (sign-in codes). Configured by the 'mail' section of config.php:
 *
 *   'mail' => [
 *       'from' => 'noreply@yourdomain.com',  // a real address on your domain
 *       'from_name' => 'Unsaid',
 *       'transport' => 'mail',               // 'mail' (the server's own), 'smtp' or 'log'
 *       // For 'smtp', e.g. Brevo or Amazon SES:
 *       'smtp_host' => 'smtp-relay.brevo.com', 'smtp_port' => 587,
 *       'smtp_user' => '...', 'smtp_pass' => '...', 'smtp_secure' => 'tls', // 'tls', 'ssl' or ''
 *       // For 'log' (local testing only): emails are appended to this file instead of sent.
 *       'log_file' => '/path/to/mail.log',
 *   ],
 */
class Mailer
{
    private string $from;
    private string $fromName;

    public function __construct(private readonly array $config)
    {
        $host = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $this->from = $config['from'] ?? "noreply@$host";
        $this->fromName = $config['from_name'] ?? 'Unsaid';
    }

    /** Throws RuntimeException if the email couldn't be handed over for delivery. */
    public function send(string $to, string $subject, string $body): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
            throw new RuntimeException('Invalid email address or subject');
        }
        match ($this->config['transport'] ?? 'mail') {
            'smtp' => $this->sendSmtp($to, $subject, $body),
            'log' => $this->sendLog($to, $subject, $body),
            default => $this->sendMail($to, $subject, $body),
        };
    }

    private function headers(string $to, string $subject): array
    {
        return [
            'From' => $this->encode($this->fromName) . " <{$this->from}>",
            'To' => $to,
            'Subject' => $this->encode($subject),
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($this->from, '@'), 1) . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'Auto-Submitted' => 'auto-generated',
        ];
    }

    private function encode(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }

    private function sendMail(string $to, string $subject, string $body): void
    {
        $headers = $this->headers($to, $subject);
        unset($headers['To'], $headers['Subject']); // mail() adds these itself.
        // -f sets the envelope sender, which helps the email pass spam checks on cPanel.
        if (!mail($to, $this->encode($subject), $body, $headers, '-f' . $this->from)) {
            throw new RuntimeException('mail() failed');
        }
    }

    private function sendLog(string $to, string $subject, string $body): void
    {
        $file = $this->config['log_file'] ?? (sys_get_temp_dir() . '/unsaid-mail.log');
        file_put_contents($file, "To: $to\nSubject: $subject\n\n$body\n----\n", FILE_APPEND | LOCK_EX);
    }

    private function sendSmtp(string $to, string $subject, string $body): void
    {
        $host = $this->config['smtp_host'] ?? throw new RuntimeException('smtp_host missing');
        $port = (int) ($this->config['smtp_port'] ?? 587);
        $secure = $this->config['smtp_secure'] ?? 'tls';
        $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $error, 15);
        if (!$socket) throw new RuntimeException("SMTP connect failed: $error");
        stream_set_timeout($socket, 15);
        try {
            $this->expect($socket, 220);
            $hostname = gethostname() ?: 'localhost';
            $this->command($socket, "EHLO $hostname", 250);
            if ($secure === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
                $this->command($socket, "EHLO $hostname", 250);
            }
            if (!empty($this->config['smtp_user'])) {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($this->config['smtp_user']), 334);
                $this->command($socket, base64_encode((string) ($this->config['smtp_pass'] ?? '')), 235);
            }
            $this->command($socket, "MAIL FROM:<{$this->from}>", 250);
            $this->command($socket, "RCPT TO:<$to>", [250, 251]);
            $this->command($socket, 'DATA', 354);
            $message = '';
            foreach ($this->headers($to, $subject) as $name => $value) $message .= "$name: $value\r\n";
            $lines = preg_split('/\r\n|\r|\n/', $body);
            $message .= "\r\n" . implode("\r\n", array_map(fn ($l) => str_starts_with($l, '.') ? ".$l" : $l, $lines)) . "\r\n.";
            $this->command($socket, $message, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $line, int|array $expected): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expected);
    }

    private function expect($socket, int|array $expected): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break; // Last line of a multi-line reply.
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) throw new RuntimeException('SMTP error: ' . trim($response));
    }
}
