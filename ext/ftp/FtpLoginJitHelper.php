<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\sockets\SocketsLibcThinAbi;
use PHPCompiler\ext\standard\TriggerErrorJitHelper;

/**
 * ftp_login() for compiled JIT/AOT modules (#31378, php-in-PHP).
 *
 * USER/PASS over JIT-owned control fd ({@see VmFtpCore::jitOwnedFdForLookupKey}).
 * NestedJIT connect skips the FTP greeting (#27393); this helper drains it before USER.
 * Line I/O stays in this PHP helper (not LLVM loops) to avoid thin-AOT insert-block orphans.
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_login)
 */
final class FtpLoginJitHelper
{
    public static function loginArgv(int $handle, string $username, string $password): bool
    {
        if ($handle <= 0) {
            return false;
        }
        $fd = VmFtpCore::jitOwnedFdForLookupKey($handle);
        if (null === $fd) {
            return false;
        }

        // ftp_connect NestedJIT does not consume the 220 greeting — drain before USER.
        $greeting = self::readReply($fd);
        if (null === $greeting || !self::isGreetingOk($greeting)) {
            TriggerErrorJitHelper::warning('ftp_login(): Login authentication failed');

            return false;
        }

        if (!self::sendLine($fd, 'USER '.$username)) {
            TriggerErrorJitHelper::warning('ftp_login(): Login authentication failed');

            return false;
        }
        $userReply = self::readReply($fd);
        if (null === $userReply) {
            TriggerErrorJitHelper::warning('ftp_login(): Login authentication failed');

            return false;
        }
        $userCode = self::replyCode($userReply);
        // 2xx after USER — already authenticated (anonymous / no password).
        if (200 <= $userCode && $userCode < 300) {
            return true;
        }
        if ($userCode < 300 || $userCode >= 400) {
            TriggerErrorJitHelper::warning('ftp_login(): Login authentication failed');

            return false;
        }

        if (!self::sendLine($fd, 'PASS '.$password)) {
            TriggerErrorJitHelper::warning('ftp_login(): Login authentication failed');

            return false;
        }
        $passReply = self::readReply($fd);
        if (null === $passReply || !self::isPassOk($passReply)) {
            TriggerErrorJitHelper::warning('ftp_login(): Login authentication failed');

            return false;
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
        // Byte loop without substr()/rtrim() — NestedJIT helper compile orphans (#31378).
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
        // Avoid ctype_digit()/substr() — NestedJIT helper compile orphans insert blocks (#31378).
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

    private static function isGreetingOk(string $line): bool
    {
        $code = self::replyCode($line);

        return 220 === $code || (120 <= $code && $code < 200);
    }

    private static function isPassOk(string $line): bool
    {
        $code = self::replyCode($line);

        return 200 <= $code && $code < 300;
    }
}
