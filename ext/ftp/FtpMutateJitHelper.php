<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\sockets\SocketsLibcThinAbi;

/**
 * ftp_mkdir / ftp_delete / ftp_rename / ftp_rmdir for JIT/AOT (#31427, php-in-PHP).
 *
 * Control-channel mutations over JIT-owned fds. Line I/O avoids ctype_digit/substr
 * (NestedJIT thin-AOT insert-block orphans; peer #31379/#31380).
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_mkdir|ftp_delete|ftp_rename|ftp_rmdir)
 */
final class FtpMutateJitHelper
{
    /**
     * Created directory path, or empty string on failure (AOT maps empty → false).
     */
    public static function mkdirArgv(int $handle, string $directory): string
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return '';
        }
        if (!self::sendLine($fd, 'MKD '.$directory)) {
            return '';
        }
        $reply = self::readReply($fd);
        if (null === $reply || 257 !== self::replyCode($reply)) {
            return '';
        }
        $path = self::extractQuotedPath($reply);

        return '' !== $path ? $path : $directory;
    }

    public static function deleteArgv(int $handle, string $filename): bool
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return false;
        }
        if (!self::sendLine($fd, 'DELE '.$filename)) {
            return false;
        }
        $reply = self::readReply($fd);

        return null !== $reply && self::isPositiveCompletion($reply);
    }

    public static function renameArgv(int $handle, string $from, string $to): bool
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return false;
        }
        if (!self::sendLine($fd, 'RNFR '.$from)) {
            return false;
        }
        $fr = self::readReply($fd);
        if (null === $fr || !self::isPositiveIntermediate($fr)) {
            return false;
        }
        if (!self::sendLine($fd, 'RNTO '.$to)) {
            return false;
        }
        $toReply = self::readReply($fd);

        return null !== $toReply && self::isPositiveCompletion($toReply);
    }

    public static function rmdirArgv(int $handle, string $directory): bool
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return false;
        }
        if (!self::sendLine($fd, 'RMD '.$directory)) {
            return false;
        }
        $reply = self::readReply($fd);

        return null !== $reply && self::isPositiveCompletion($reply);
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

    private static function isPositiveIntermediate(string $line): bool
    {
        $code = self::replyCode($line);

        return 300 <= $code && $code < 400;
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
