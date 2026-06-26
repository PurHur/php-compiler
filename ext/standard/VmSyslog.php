<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * openlog/syslog/closelog for VM — pure PHP via {@see VmSyslogPure} (#3676, #12211).
 *
 * php-src: ext/standard/syslog.c, main/php_syslog.c
 */
final class VmSyslog
{
    public static function available(): bool
    {
        return VmSyslogPure::available();
    }

    public static function openlog(string $ident, int $option, int $facility): bool
    {
        return VmSyslogPure::openlog($ident, $option, $facility);
    }

    public static function closelog(): bool
    {
        return VmSyslogPure::closelog();
    }

    public static function syslog(int $priority, string $message): bool
    {
        return VmSyslogPure::syslog($priority, $message);
    }
}
