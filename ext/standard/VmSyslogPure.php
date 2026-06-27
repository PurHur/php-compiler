<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * openlog/syslog/closelog without libc FFI — write RFC3164-style records to /dev/log (#12211).
 *
 * php-src: ext/standard/syslog.c, main/php_syslog.c
 */
final class VmSyslogPure
{
    private static ?string $ident = null;

    private static int $option = 0;

    private static int $facility = StdlibConstants::LOG_USER;

    private static bool $opened = false;

    /** @var list<string> */
    private const LOG_PATHS = ['/dev/log', '/var/run/syslog', '/var/run/rsyslog.sock'];

    public static function available(): bool
    {
        foreach (self::LOG_PATHS as $path) {
            if (\file_exists($path)) {
                return true;
            }
        }

        return false;
    }

    public static function openlog(string $ident, int $option, int $facility): bool
    {
        self::$ident = $ident;
        self::$option = $option;
        self::$facility = $facility;
        self::$opened = true;

        return true;
    }

    public static function closelog(): bool
    {
        self::$ident = null;
        self::$option = 0;
        self::$facility = StdlibConstants::LOG_USER;
        self::$opened = false;

        return true;
    }

    public static function syslog(int $priority, string $message): bool
    {
        if (!self::$opened) {
            self::openlog('php', 0, StdlibConstants::LOG_USER);
        }

        $pri = self::$facility | ($priority & 0x07);
        $tag = self::$ident ?? 'php';
        if (0 !== (self::$option & StdlibConstants::LOG_PID)) {
            $pid = VmProcessIdentityPure::getpid();
            $tag .= '['.(null !== $pid ? $pid : 0).']';
        }

        $line = '<'.$pri.'>'.$tag.': '.$message;
        $sent = self::writeLog($line);
        if (!$sent && 0 !== (self::$option & StdlibConstants::LOG_CONS)) {
            $sent = self::writeConsole($line);
        }
        if (0 !== (self::$option & StdlibConstants::LOG_PERROR)) {
            self::writeConsole($line);
        }

        // php-src ext/standard/syslog.c PHP_FUNCTION(syslog) always RETURN_TRUE (#12507).
        return true;
    }

    private static function writeLog(string $line): bool
    {
        foreach (self::LOG_PATHS as $path) {
            if (!\file_exists($path)) {
                continue;
            }
            if (false !== @\file_put_contents($path, $line)) {
                return true;
            }
        }

        return false;
    }

    private static function writeConsole(string $line): bool
    {
        return false !== @\file_put_contents('php://stderr', $line.\PHP_EOL);
    }
}
