<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * mail() sendmail transport — php-src ext/standard/mail.c `php_mail()` (#3285).
 *
 * PHP-in-PHP: popen(sendmail_path) + RFC822 envelope on stdin; no new runtime C.
 */
final class VmMail
{
    /** sysexits.h EX_OK — accepted by php_mail() after pclose(). */
    private const EX_OK = 0;

    /** sysexits.h EX_TEMPFAIL — php_mail() also treats as success. */
    private const EX_TEMPFAIL = 75;

    /**
     * Deliver via INI `sendmail_path` (mirrored host / `-d` / PHP_COMPILER_INI_SENDMAIL_PATH).
     */
    public static function send(
        Frame $frame,
        string $to,
        string $subject,
        string $message,
        ?string $headers,
        ?string $extraParams
    ): bool {
        $to = self::scrubAddressOrSubject($to);
        $subject = self::scrubAddressOrSubject($subject);
        if (null !== $headers) {
            $headers = rtrim($headers);
            if ('' === $headers) {
                $headers = null;
            }
        }

        $sendmailPath = VmIniIntrospection::mirroredHostIniGet('sendmail_path');
        if (null === $sendmailPath || '' === $sendmailPath) {
            return false;
        }

        $cmd = $sendmailPath;
        if (null !== $extraParams && '' !== $extraParams) {
            $cmd .= ' '.VmEscapeshell::escapeshellcmd($extraParams);
        }

        $handle = VmFs::popen($cmd, 'w');
        if (false === $handle) {
            self::warn(
                $frame,
                \sprintf("mail(): Could not execute mail delivery program '%s'", $sendmailPath)
            );

            return false;
        }

        $sep = "\r\n";
        $payload = 'To: '.$to.$sep.'Subject: '.$subject.$sep;
        if (null !== $headers) {
            $payload .= $headers.$sep;
        }
        $payload .= $sep.$message.$sep;
        VmFs::fwrite($handle, $payload);
        $ret = VmFs::pclose($handle);

        if (self::EX_OK !== $ret && self::EX_TEMPFAIL !== $ret) {
            return false;
        }

        return true;
    }

    /**
     * php-src mail.c — trim trailing whitespace; replace bare control chars with space
     * while preserving RFC822 long-header CRLF+WSP folding.
     */
    private static function scrubAddressOrSubject(string $value): string
    {
        $len = \strlen($value);
        if (0 === $len) {
            return $value;
        }
        while ($len > 0 && self::isAsciiSpace($value[$len - 1])) {
            --$len;
        }
        if ($len !== \strlen($value)) {
            $value = \substr($value, 0, $len);
        }
        $out = '';
        $i = 0;
        $n = \strlen($value);
        while ($i < $n) {
            $ch = $value[$i];
            if ("\r" === $ch && ($i + 1) < $n && "\n" === $value[$i + 1]
                && ($i + 2) < $n && (' ' === $value[$i + 2] || "\t" === $value[$i + 2])) {
                $out .= "\r\n";
                $i += 2;
                while ($i < $n && (' ' === $value[$i] || "\t" === $value[$i])) {
                    $out .= $value[$i];
                    ++$i;
                }

                continue;
            }
            $ord = \ord($ch);
            if ($ord < 32 || 127 === $ord) {
                $out .= ' ';
            } else {
                $out .= $ch;
            }
            ++$i;
        }

        return $out;
    }

    private static function isAsciiSpace(string $ch): bool
    {
        return ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch || "\v" === $ch || "\f" === $ch;
    }

    private static function warn(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerErrorWithHandlerFirst(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
