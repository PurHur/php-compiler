<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\sockets\SocketConstants;
use PHPCompiler\ext\sockets\SocketsLibcThinAbi;
use PHPCompiler\ext\sockets\VmSockets;
use PHPCompiler\ext\standard\VmFs;

/**
 * ftp_get / ftp_put / ftp_fget / ftp_fput for JIT/AOT (#31429, php-in-PHP).
 *
 * PASV data transfers after {@see VmFtpCore::setJitPasvMode} (peer listings #31428).
 * Local paths via `@file_get_contents` / `@file_put_contents` (NestedJIT libc leaf);
 * stream handles via {@see VmFs}. Line I/O avoids ctype_digit/substr/explode.
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_get|ftp_put|ftp_fget|ftp_fput)
 */
final class FtpTransferJitHelper
{
    /** FTP_ASCII / FTP_TEXT (php-src FTP_ASCII). */
    private const MODE_ASCII = 1;

    public static function getArgv(
        int $handle,
        string $localFile,
        string $remoteFile,
        int $mode,
        int $offset
    ): bool {
        $payload = self::retrBytes($handle, $remoteFile, $mode, $offset);
        if (null === $payload) {
            return false;
        }
        $written = @\file_put_contents($localFile, $payload);
        if (false === $written) {
            return false;
        }

        return true;
    }

    public static function putArgv(
        int $handle,
        string $remoteFile,
        string $localFile,
        int $mode,
        int $offset
    ): bool {
        $payload = @\file_get_contents($localFile);
        if (false === $payload) {
            return false;
        }

        return self::storBytes($handle, $remoteFile, $payload, $mode, $offset);
    }

    public static function fgetArgv(
        int $handle,
        int $streamHandle,
        string $remoteFile,
        int $mode,
        int $offset
    ): bool {
        $payload = self::retrBytes($handle, $remoteFile, $mode, $offset);
        if (null === $payload) {
            return false;
        }
        if ($streamHandle <= 0) {
            return false;
        }
        $written = VmFs::fwrite($streamHandle, $payload);
        if (false === $written) {
            return false;
        }

        return true;
    }

    public static function fputArgv(
        int $handle,
        string $remoteFile,
        int $streamHandle,
        int $mode,
        int $offset
    ): bool {
        if ($streamHandle <= 0) {
            return false;
        }
        $payload = VmFs::streamGetContents($streamHandle);
        if (false === $payload) {
            return false;
        }

        return self::storBytes($handle, $remoteFile, (string) $payload, $mode, $offset);
    }

    private static function retrBytes(
        int $handle,
        string $remoteFile,
        int $mode,
        int $offset
    ): ?string {
        $fd = self::fd($handle);
        if (null === $fd) {
            return null;
        }
        if (!VmFtpCore::jitPasvEnabled($handle)) {
            return null;
        }
        if (!self::sendType($fd, $mode)) {
            return null;
        }
        if ($offset > 0 && !self::sendRest($fd, $offset)) {
            return null;
        }
        $dataFd = self::openPasvDataFd($fd);
        if ($dataFd < 0) {
            return null;
        }
        if (!self::sendLine($fd, 'RETR '.$remoteFile)) {
            SocketsLibcThinAbi::close($dataFd);

            return null;
        }
        $openReply = self::readReply($fd);
        if (null === $openReply || !self::isPositivePreliminary($openReply)) {
            SocketsLibcThinAbi::close($dataFd);

            return null;
        }
        $bytes = self::readDataBytes($dataFd);
        SocketsLibcThinAbi::close($dataFd);
        $done = self::readReply($fd);
        if (null === $done || !self::isPositiveCompletion($done)) {
            return null;
        }

        return $bytes;
    }

    private static function storBytes(
        int $handle,
        string $remoteFile,
        string $payload,
        int $mode,
        int $offset
    ): bool {
        $fd = self::fd($handle);
        if (null === $fd) {
            return false;
        }
        if (!VmFtpCore::jitPasvEnabled($handle)) {
            return false;
        }
        if (!self::sendType($fd, $mode)) {
            return false;
        }
        if ($offset > 0 && !self::sendRest($fd, $offset)) {
            return false;
        }
        $dataFd = self::openPasvDataFd($fd);
        if ($dataFd < 0) {
            return false;
        }
        if (!self::sendLine($fd, 'STOR '.$remoteFile)) {
            SocketsLibcThinAbi::close($dataFd);

            return false;
        }
        $openReply = self::readReply($fd);
        if (null === $openReply || !self::isPositivePreliminary($openReply)) {
            SocketsLibcThinAbi::close($dataFd);

            return false;
        }
        $ok = self::sendDataBytes($dataFd, $payload);
        SocketsLibcThinAbi::close($dataFd);
        if (!$ok) {
            return false;
        }
        $done = self::readReply($fd);

        return null !== $done && self::isPositiveCompletion($done);
    }

    private static function sendType(int $fd, int $mode): bool
    {
        $cmd = self::MODE_ASCII === $mode ? 'TYPE A' : 'TYPE I';

        if (!self::sendLine($fd, $cmd)) {
            return false;
        }
        $reply = self::readReply($fd);

        return null !== $reply && self::isPositiveCompletion($reply);
    }

    private static function sendRest(int $fd, int $offset): bool
    {
        if (!self::sendLine($fd, 'REST '.$offset)) {
            return false;
        }
        $reply = self::readReply($fd);
        if (null === $reply) {
            return false;
        }
        $code = self::replyCode($reply);

        // 350 Requested file action pending further information.
        return 350 === $code || self::isPositiveCompletion($reply);
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

    private static function readDataBytes(int $fd): string
    {
        $buf = '';
        for ($n = 0; $n < 1_048_576; ++$n) {
            $chunk = SocketsLibcThinAbi::recv($fd, 4096, 0);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private static function sendDataBytes(int $fd, string $data): bool
    {
        $len = \strlen($data);
        if (0 === $len) {
            return true;
        }
        // Byte loop — avoid substr() in NestedJIT helpers (#31378 orphans).
        for ($i = 0; $i < $len; ++$i) {
            $n = SocketsLibcThinAbi::send($fd, $data[$i], 1, 0);
            if (1 !== $n) {
                return false;
            }
        }

        return true;
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
}
