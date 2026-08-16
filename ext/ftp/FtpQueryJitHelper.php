<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\sockets\SocketConstants;
use PHPCompiler\ext\sockets\SocketsLibcThinAbi;
use PHPCompiler\ext\sockets\VmSockets;

/**
 * ftp_size / ftp_mdtm / ftp_systype / ftp_nlist for JIT/AOT (#31380, php-in-PHP).
 *
 * Control-channel SIZE/MDTM/SYST; NLST opens a PASV data connection after
 * {@see VmFtpCore::setJitPasvMode}. Line I/O avoids ctype_digit/substr
 * (NestedJIT thin-AOT insert-block orphans; peer #31379).
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_size|ftp_mdtm|ftp_systype|ftp_nlist)
 */
final class FtpQueryJitHelper
{
    /**
     * Remote file size, or -1 on failure (Zend ftp_size).
     */
    public static function sizeArgv(int $handle, string $filename): int
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return -1;
        }
        if (!self::sendLine($fd, 'SIZE '.$filename)) {
            return -1;
        }
        $reply = self::readReply($fd);
        if (null === $reply || 213 !== self::replyCode($reply)) {
            return -1;
        }

        return self::intAfterCode($reply);
    }

    /**
     * Modification time as unix timestamp, or -1 on failure (Zend ftp_mdtm).
     */
    public static function mdtmArgv(int $handle, string $filename): int
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return -1;
        }
        if (!self::sendLine($fd, 'MDTM '.$filename)) {
            return -1;
        }
        $reply = self::readReply($fd);
        if (null === $reply || 213 !== self::replyCode($reply)) {
            return -1;
        }

        return self::mdtmStampAfterCode($reply);
    }

    /**
     * System type string, or empty on failure (AOT maps empty → false).
     */
    public static function systypeArgv(int $handle): string
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return '';
        }
        if (!self::sendLine($fd, 'SYST')) {
            return '';
        }
        $reply = self::readReply($fd);
        if (null === $reply || 215 !== self::replyCode($reply)) {
            return '';
        }

        return self::textAfterCode($reply);
    }

    /**
     * NLST names, or null on failure (Zend false). Requires PASV mode (#31379).
     *
     * @return list<string>|null
     */
    public static function nlistArgv(int $handle, string $directory): ?array
    {
        $fd = self::fd($handle);
        if (null === $fd) {
            return null;
        }
        if (!VmFtpCore::jitPasvEnabled($handle)) {
            return null;
        }
        $dataFd = self::openPasvDataFd($fd);
        if ($dataFd < 0) {
            return null;
        }
        if (!self::sendLine($fd, 'NLST '.$directory)) {
            SocketsLibcThinAbi::close($dataFd);

            return null;
        }
        $openReply = self::readReply($fd);
        if (null === $openReply || !self::isPositivePreliminary($openReply)) {
            SocketsLibcThinAbi::close($dataFd);

            return null;
        }
        $lines = self::readDataLines($dataFd);
        SocketsLibcThinAbi::close($dataFd);
        $done = self::readReply($fd);
        if (null === $done || !self::isPositiveCompletion($done)) {
            return null;
        }

        return $lines;
    }

    private static function fd(int $handle): ?int
    {
        if ($handle <= 0) {
            return null;
        }

        return VmFtpCore::jitOwnedFdForLookupKey($handle);
    }

    private static function openPasvDataFd(int $controlFd): int
    {
        if (!SocketsLibcThinAbi::available()) {
            return -1;
        }
        if (!self::sendLine($controlFd, 'PASV')) {
            return -1;
        }
        $reply = self::readReply($controlFd);
        if (null === $reply || 227 !== self::replyCode($reply)) {
            return -1;
        }
        $endpoint = self::parsePasvEndpoint($reply);
        if (null === $endpoint) {
            return -1;
        }
        $dataFd = SocketsLibcThinAbi::socket(VmSockets::AF_INET, SocketConstants::SOCK_STREAM, 0);
        if ($dataFd < 0) {
            return -1;
        }
        $rc = SocketsLibcThinAbi::connectInet($dataFd, $endpoint[0], $endpoint[1]);
        if (0 !== $rc) {
            SocketsLibcThinAbi::close($dataFd);

            return -1;
        }

        return $dataFd;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function parsePasvEndpoint(string $line): ?array
    {
        $len = \strlen($line);
        $i = 0;
        while ($i < $len && '(' !== $line[$i]) {
            ++$i;
        }
        if ($i < $len) {
            ++$i;
        } else {
            $i = 4;
        }
        $nums = [];
        $cur = 0;
        $have = false;
        for (; $i < $len; ++$i) {
            $c = $line[$i];
            if ($c >= '0' && $c <= '9') {
                $cur = $cur * 10 + (\ord($c) - 48);
                $have = true;
            } elseif (',' === $c) {
                if (!$have) {
                    return null;
                }
                $nums[] = $cur;
                $cur = 0;
                $have = false;
                if (6 === \count($nums)) {
                    break;
                }
            } elseif (')' === $c) {
                if ($have) {
                    $nums[] = $cur;
                }
                break;
            } elseif ($have) {
                $nums[] = $cur;
                break;
            }
        }
        if ($have && \count($nums) < 6) {
            $nums[] = $cur;
        }
        if (\count($nums) < 6) {
            return null;
        }
        $host = ((string) $nums[0]).'.'.((string) $nums[1]).'.'.((string) $nums[2]).'.'.((string) $nums[3]);
        $port = $nums[4] * 256 + $nums[5];

        return [$host, $port];
    }

    /**
     * @return list<string>
     */
    private static function readDataLines(int $fd): array
    {
        $lines = [];
        $cur = '';
        for ($n = 0; $n < 1_048_576; ++$n) {
            $chunk = SocketsLibcThinAbi::recv($fd, 1, 0);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $ch = $chunk[0];
            if ("\n" === $ch) {
                $lines[] = $cur;
                $cur = '';
                continue;
            }
            if ("\r" === $ch) {
                continue;
            }
            $cur .= $ch;
        }
        if ('' !== $cur) {
            $lines[] = $cur;
        }

        return $lines;
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

    private static function isPositivePreliminary(string $line): bool
    {
        $code = self::replyCode($line);

        return 100 <= $code && $code < 200;
    }

    private static function intAfterCode(string $line): int
    {
        $len = \strlen($line);
        $i = 3;
        while ($i < $len && (' ' === $line[$i] || '-' === $line[$i])) {
            ++$i;
        }
        $n = 0;
        $any = false;
        while ($i < $len) {
            $c = $line[$i];
            if ($c < '0' || $c > '9') {
                break;
            }
            $any = true;
            $n = $n * 10 + (\ord($c) - 48);
            ++$i;
        }

        return $any ? $n : -1;
    }

    private static function textAfterCode(string $line): string
    {
        $len = \strlen($line);
        $i = 3;
        while ($i < $len && (' ' === $line[$i] || '-' === $line[$i])) {
            ++$i;
        }
        $out = '';
        while ($i < $len) {
            $out .= $line[$i];
            ++$i;
        }

        return $out;
    }

    private static function mdtmStampAfterCode(string $line): int
    {
        $len = \strlen($line);
        $i = 3;
        while ($i < $len && (' ' === $line[$i] || '-' === $line[$i])) {
            ++$i;
        }
        $digits = '';
        while ($i < $len) {
            $c = $line[$i];
            if ($c < '0' || $c > '9') {
                break;
            }
            $digits .= $c;
            ++$i;
        }
        if (14 !== \strlen($digits)) {
            return -1;
        }
        $y = self::digitSlice($digits, 0, 4);
        $mo = self::digitSlice($digits, 4, 2);
        $d = self::digitSlice($digits, 6, 2);
        $h = self::digitSlice($digits, 8, 2);
        $m = self::digitSlice($digits, 10, 2);
        $s = self::digitSlice($digits, 12, 2);
        // php-src ftp.c uses mktime() (local) after sscanf of YYYYMMDDhhmmss.
        $stamp = @\mktime($h, $m, $s, $mo, $d, $y);

        return false === $stamp ? -1 : (int) $stamp;
    }

    private static function digitSlice(string $digits, int $start, int $count): int
    {
        $n = 0;
        $end = $start + $count;
        for ($i = $start; $i < $end; ++$i) {
            $n = $n * 10 + (\ord($digits[$i]) - 48);
        }

        return $n;
    }
}
