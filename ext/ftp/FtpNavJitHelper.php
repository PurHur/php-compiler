<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\sockets\SocketsLibcThinAbi;

/**
 * ftp_pasv / ftp_chdir / ftp_cdup / ftp_pwd for JIT/AOT (#31379, php-in-PHP).
 *
 * Control-channel commands over JIT-owned fds. Line I/O avoids ctype_digit/substr
 * (NestedJIT thin-AOT insert-block orphans; peer #31378).
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_pasv|ftp_chdir|ftp_cdup|ftp_pwd)
 */
final class FtpNavJitHelper
{
    public static function pasvArgv(int $handle, bool $enable): bool
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return false;
        }
        if (!$enable) {
            VmFtpCore::setJitPasvMode($handle, false);

            return true;
        }
        if (!self::sendLine($fd, 'PASV')) {
            return false;
        }
        $reply = self::readReply($fd);
        if (null === $reply || !self::isPositiveCompletion($reply)) {
            return false;
        }
        VmFtpCore::setJitPasvMode($handle, true);

        return true;
    }

    public static function chdirArgv(int $handle, string $directory): bool
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return false;
        }
        if (!self::sendLine($fd, 'CWD '.$directory)) {
            return false;
        }
        $reply = self::readReply($fd);

        return null !== $reply && self::isPositiveCompletion($reply);
    }

    public static function cdupArgv(int $handle): bool
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return false;
        }
        if (!self::sendLine($fd, 'CDUP')) {
            return false;
        }
        $reply = self::readReply($fd);

        return null !== $reply && self::isPositiveCompletion($reply);
    }

    /**
     * Current directory path, or empty string on failure (AOT maps empty → false).
     */
    public static function pwdArgv(int $handle): string
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return '';
        }
        if (!self::sendLine($fd, 'PWD')) {
            return '';
        }
        $reply = self::readReply($fd);
        if (null === $reply || !self::isPositiveCompletion($reply)) {
            return '';
        }

        return self::extractQuotedPath($reply);
    }

    private static function fd(int $handle): ?int
    {
        if ($handle <= 0) {
            return null;
        }

        return VmFtpCore::jitOwnedFdForLookupKey($handle);
    }

    private static function sendLine(int $fd, string $command): bool
    {
        $payload = $command."\r\n";
        $n = SocketsLibcThinAbi::send($fd, $payload, \strlen($payload), 0);

        return $n === \strlen($payload);
    }

    private static function readReply(int $fd): ?string
    {
        $buf = '';
        for ($i = 0; $i < 512; ++$i) {
            $chunk = SocketsLibcThinAbi::recv($fd, 1, 0);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $ch = $chunk[0];
            if ("\n" === $ch) {
                return '' === $buf ? null : $buf;
            }
            if ("\r" === $ch) {
                continue;
            }
            $buf .= $ch;
        }

        return '' === $buf ? null : $buf;
    }

    private static function replyCode(string $line): int
    {
        if (\strlen($line) < 3) {
            return 0;
        }
        $c0 = $line[0];
        $c1 = $line[1];
        $c2 = $line[2];
        if ($c0 < '0' || $c0 > '9' || $c1 < '0' || $c1 > '9' || $c2 < '0' || $c2 > '9') {
            return 0;
        }

        return (\ord($c0) - 48) * 100 + (\ord($c1) - 48) * 10 + (\ord($c2) - 48);
    }

    private static function isPositiveCompletion(string $line): bool
    {
        $code = self::replyCode($line);

        return 200 <= $code && $code < 300;
    }

    private static function extractQuotedPath(string $line): string
    {
        $len = \strlen($line);
        $start = -1;
        for ($i = 0; $i < $len; ++$i) {
            if ('"' === $line[$i]) {
                $start = $i + 1;
                break;
            }
        }
        if ($start < 0) {
            return '';
        }
        $out = '';
        for ($i = $start; $i < $len; ++$i) {
            if ('"' === $line[$i]) {
                break;
            }
            $out .= $line[$i];
        }

        return $out;
    }
}
