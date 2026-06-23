<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * openlog()/syslog()/closelog() for compiled JIT/AOT modules (#9254, php-in-PHP).
 *
 * SSOT: {@see VmSyslog}
 * php-src: ext/standard/syslog.c
 */
final class SyslogJitHelper
{
    public static function openlog(string $ident, int $option, int $facility): bool
    {
        return VmSyslog::openlog($ident, $option, $facility);
    }

    public static function write(int $priority, string $message): bool
    {
        return VmSyslog::syslog($priority, $message);
    }

    public static function closelog(): bool
    {
        return VmSyslog::closelog();
    }
}
