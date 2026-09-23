<?php
declare(strict_types=1);

namespace App\Services;

final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->host = (string) app_env(
            'SMTP_HOST',
            'smtp.gmail.com'
        );

        $this->port = (int) app_env(
            'SMTP_PORT',
            '465'
        );

        $this->username = (string) app_env(
            'SMTP_USERNAME'
        );

        $this->password = (string) app_env(
            'SMTP_PASSWORD'
        );

        $this->fromEmail = (string) app_env(
            'SMTP_FROM_EMAIL',
            $this->username
        );

        $this->fromName = (string) app_env(
            'SMTP_FROM_NAME',
            'Граждански сигнал'
        );

        if (
            $this->host === '' ||
            $this->username === '' ||
            $this->password === '' ||
            $this->fromEmail === ''
        ) {
            throw new \RuntimeException(
                'SMTP configuration is incomplete.'
            );
        }
    }

    /**
     * Send a plain-text email with optional attachments.
     *
     * @param array<int, array{
     *     path:string,
     *     filename?:string,
     *     mime?:string
     * }> $attachments
     *
     * @return array{message_id:string}
     */
    public function send(
        string $recipient,
        string $subject,
        string $body,
        array $attachments = []
    ): array {
        $socket = fsockopen(
            'ssl://' . $this->host,
            $this->port,
            $errno,
            $errstr,
            15
        );

        if (!$socket) {
            throw new \RuntimeException(
                "SMTP connection failed: {$errno} {$errstr}"
            );
        }

        stream_set_timeout(
            $socket,
            15
        );

        try {
            $response = $this->read($socket);

            $this->expect(
                $response,
                [220],
                'SMTP greeting'
            );

            $this->command(
                $socket,
                'EHLO localhost',
                [250]
            );

            $this->command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            $this->command(
                $socket,
                base64_encode($this->username),
                [334]
            );

            $this->command(
                $socket,
                base64_encode($this->password),
                [235]
            );

            $this->command(
                $socket,
                'MAIL FROM:<' . $this->fromEmail . '>',
                [250]
            );

            $this->command(
                $socket,
                'RCPT TO:<' . $recipient . '>',
                [250, 251]
            );

            $this->command(
                $socket,
                'DATA',
                [354]
            );

            $messageId = $this->messageId();

            $message = $this->buildMessage(
                $recipient,
                $subject,
                $body,
                $messageId,
                $attachments
            );

            /*
             * SMTP dot-stuffing.
             *
             * This must be applied to the complete MIME message.
             */
            $message = preg_replace(
                '/^\\./m',
                '..',
                $message
            ) ?? $message;

            /*
             * IMPORTANT:
             *
             * Do not assume one fwrite() writes the entire
             * MIME message. Large attachments can produce
             * multi-megabyte SMTP DATA blocks.
             */
            $smtpData =
                $message .
                "\r\n.\r\n";

            $this->writeAll(
                $socket,
                $smtpData
            );

            $response = $this->read(
                $socket
            );

            $this->expect(
                $response,
                [250],
                'SMTP message submission'
            );

            $this->command(
                $socket,
                'QUIT',
                [221]
            );

            fclose($socket);

            return [
                'message_id' => $messageId,
            ];

        } catch (\Throwable $e) {

            fclose($socket);

            throw $e;
        }
    }

    /**
     * Write all data to the SMTP socket.
     *
     * fwrite() is allowed to write fewer bytes than requested.
     * This method continues until the complete buffer has been
     * transmitted.
     */
    private function writeAll(
        mixed $socket,
        string $data
    ): void {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite(
                $socket,
                substr($data, $offset)
            );

            if (
                $written === false ||
                $written === 0
            ) {
                throw new \RuntimeException(
                    'SMTP connection closed while sending message data.'
                );
            }

            $offset += $written;
        }
    }

    /**
     * Build the complete MIME message.
     *
     * Without attachments this remains a normal text/plain
     * message.
     *
     * With attachments it becomes multipart/mixed.
     *
     * @param array<int, array{
     *     path:string,
     *     filename?:string,
     *     mime?:string
     * }> $attachments
     */
    private function buildMessage(
        string $recipient,
        string $subject,
        string $body,
        string $messageId,
        array $attachments
    ): string {
        $body = str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

        if ($attachments === []) {
            return
                'Date: ' .
                gmdate('D, d M Y H:i:s') .
                " +0000\r\n" .

                'From: ' .
                $this->encodeHeader($this->fromName) .
                ' <' .
                $this->fromEmail .
                ">\r\n" .

                'To: <' .
                $recipient .
                ">\r\n" .

                'Subject: ' .
                $this->encodeHeader($subject) .
                "\r\n" .

                'Message-ID: <' .
                $messageId .
                ">\r\n" .

                "MIME-Version: 1.0\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit\r\n" .
                "\r\n" .

                str_replace(
                    "\n",
                    "\r\n",
                    $body
                );
        }

        $boundary =
            '=_CIVIC_SIGNAL_' .
            bin2hex(random_bytes(16));

        $message =
            'Date: ' .
            gmdate('D, d M Y H:i:s') .
            " +0000\r\n" .

            'From: ' .
            $this->encodeHeader($this->fromName) .
            ' <' .
            $this->fromEmail .
            ">\r\n" .

            'To: <' .
            $recipient .
            ">\r\n" .

            'Subject: ' .
            $this->encodeHeader($subject) .
            "\r\n" .

            'Message-ID: <' .
            $messageId .
            ">\r\n" .

            "MIME-Version: 1.0\r\n" .

            'Content-Type: multipart/mixed; boundary="' .
            $boundary .
            "\"\r\n" .

            "\r\n" .

            '--' .
            $boundary .
            "\r\n" .

            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n" .
            "\r\n" .

            str_replace(
                "\n",
                "\r\n",
                $body
            ) .

            "\r\n";

        foreach ($attachments as $attachment) {
            $message .= $this->buildAttachmentPart(
                $boundary,
                $attachment
            );
        }

        $message .=
            '--' .
            $boundary .
            "--\r\n";

        return $message;
    }

    /**
     * Build one MIME attachment part.
     *
     * @param array{
     *     path:string,
     *     filename?:string,
     *     mime?:string
     * } $attachment
     */
    private function buildAttachmentPart(
        string $boundary,
        array $attachment
    ): string {
        $path = (string)(
            $attachment['path'] ?? ''
        );

        if (
            $path === '' ||
            !is_file($path) ||
            !is_readable($path)
        ) {
            throw new \RuntimeException(
                'Email attachment file is missing or unreadable.'
            );
        }

        $filename = (string)(
            $attachment['filename'] ??
            basename($path)
        );

        if ($filename === '') {
            $filename = 'attachment';
        }

        /*
         * Prevent malformed Content-Disposition headers.
         */
        $filename = preg_replace(
            '/[\x00-\x1F\x7F"\\\\\/]+/',
            '_',
            $filename
        ) ?: 'attachment';

        $mime = (string)(
            $attachment['mime'] ??
            'application/octet-stream'
        );

        /*
         * Determine MIME type from the actual file.
         */
        $finfo = new \finfo(
            FILEINFO_MIME_TYPE
        );

        $detectedMime = $finfo->file(
            $path
        );

        if (
            is_string($detectedMime) &&
            $detectedMime !== ''
        ) {
            $mime = $detectedMime;
        }

        $contents = file_get_contents(
            $path
        );

        if ($contents === false) {
            throw new \RuntimeException(
                'Email attachment could not be read.'
            );
        }

        /*
         * RFC-compliant Base64 line wrapping.
         *
         * 76 characters per line with CRLF.
         */
        $encoded = chunk_split(
            base64_encode($contents),
            76,
            "\r\n"
        );

        /*
         * RFC 2231 filename handling.
         */
        $encodedFilename = rawurlencode(
            $filename
        );

        return
            '--' .
            $boundary .
            "\r\n" .

            'Content-Type: ' .
            $mime .
            '; name="' .
            $filename .
            "\"\r\n" .

            "Content-Transfer-Encoding: base64\r\n" .

            'Content-Disposition: attachment; filename="' .
            $filename .
            '"; filename*=UTF-8\'\'' .
            $encodedFilename .
            "\r\n" .

            "\r\n" .

            $encoded;
    }

    /**
     * Send one SMTP command and validate the response.
     */
    private function command(
        mixed $socket,
        string $command,
        array $expectedCodes
    ): string {
        fwrite(
            $socket,
            $command . "\r\n"
        );

        $response = $this->read(
            $socket
        );

        $this->expect(
            $response,
            $expectedCodes,
            $command
        );

        return $response;
    }

    /**
     * Read one complete SMTP response.
     */
    private function read(
        mixed $socket
    ): string {
        $response = '';

        while (
            ($line = fgets($socket)) !== false
        ) {
            $response .= $line;

            if (
                strlen($line) >= 4 &&
                $line[3] === ' '
            ) {
                break;
            }
        }

        return $response;
    }

    /**
     * Validate an SMTP response code.
     */
    private function expect(
        string $response,
        array $expectedCodes,
        string $operation
    ): void {
        $code = (int)substr(
            $response,
            0,
            3
        );

        if (
            !in_array(
                $code,
                $expectedCodes,
                true
            )
        ) {
            throw new \RuntimeException(
                "SMTP error during {$operation}: " .
                trim($response)
            );
        }
    }

    /**
     * Encode a UTF-8 mail header.
     */
    private function encodeHeader(
        string $value
    ): string {
        return '=?UTF-8?B?' .
            base64_encode($value) .
            '?=';
    }

    /**
     * Generate a unique Message-ID.
     */
    private function messageId(): string
    {
        return bin2hex(
            random_bytes(16)
        ) .
        '@' .
        preg_replace(
            '/[^A-Za-z0-9.-]/',
            '',
            $this->host
        );
    }
}