<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_output_handler() NestedJIT runtime (#20014 leftover — compile-time-only args blocked AOT).
 *
 * Encoding is passed as int codes from module globals (peer {@see MbHttpOutputJitHelper}).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_output_handler)
 */
final class MbOutputHandlerJitHelper
{
    public static function nameFromCodeArgv(int $code): string
    {
        return match ($code) {
            MbHttpOutputJitHelper::CODE_UTF8 => 'UTF-8',
            MbHttpOutputJitHelper::CODE_ASCII => 'ASCII',
            MbHttpOutputJitHelper::CODE_ISO88591 => 'ISO-8859-1',
            MbHttpOutputJitHelper::CODE_SJIS => 'SJIS',
            MbHttpOutputJitHelper::CODE_EUCJP => 'EUC-JP',
            MbHttpOutputJitHelper::CODE_8BIT => '8BIT',
            MbHttpOutputJitHelper::CODE_PASS => 'pass',
            default => 'UTF-8',
        };
    }

    public static function convertArgv(string $string, int $httpCode, int $internalCode): string
    {
        if (MbHttpOutputJitHelper::CODE_PASS === $httpCode) {
            return $string;
        }
        $http = self::nameFromCodeArgv($httpCode);
        $from = self::nameFromCodeArgv($internalCode);
        if (0 === strcasecmp($from, $http)) {
            return $string;
        }

        return MbConvertEncodingJitHelper::convertArgv($string, $http, $from);
    }
}
