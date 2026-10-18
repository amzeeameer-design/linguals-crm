<?php

namespace App\Services;

class SmtpDiagnostics
{
    public static function message(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        if (str_contains($message, '535') || str_contains($message, 'authenticate') || str_contains($message, 'authentication')) {
            return 'SMTP login was rejected. Check the username and SMTP/app password with your mail provider.';
        }
        if (str_contains($message, 'certificate') || str_contains($message, 'crypto') || str_contains($message, 'starttls') || str_contains($message, 'tls')) {
            return 'The secure SMTP connection failed. Verify the hostname and SSL/STARTTLS setting. Port 465 uses SSL; port 587 uses STARTTLS. The server certificate must be valid.';
        }
        if (str_contains($message, 'getaddrinfo') || str_contains($message, 'name resolution') || str_contains($message, 'php_network_getaddresses')) {
            return 'The SMTP hostname could not be resolved. Check the mail server hostname and hosting DNS.';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'refused') || str_contains($message, 'connection could not')) {
            return 'The hosting server could not connect to SMTP. Confirm the hostname and port, then ask hosting support whether outbound SMTP on that port is allowed.';
        }
        if (str_contains($message, '550') || str_contains($message, '553') || str_contains($message, 'sender') || str_contains($message, 'relay')) {
            return 'The mail server rejected the sender or recipient. Use an authorized sender address and confirm the mailbox and relay permissions.';
        }

        return 'The SMTP server did not accept the test. Confirm the server, port, encryption, login and authorized sender with your provider. No credentials are displayed here.';
    }
}
