<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * convert_cyr_string() — Cyrillic charset transliteration (php-src cyr_convert.c, #4649).
 */
final class VmConvertCyrString
{
    public static function convert(string $str, string $from, string $to, ?Frame $frame = null): string
    {
        $fromTable = self::tableFor($from, true, $frame);
        $toTable = self::tableFor($to, false, $frame);

        $len = \strlen($str);
        if (0 === $len) {
            return '';
        }

        $result = '';
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($str[$i]);
            $tmp = null === $fromTable ? $byte : \ord($fromTable[$byte]);
            $result .= null === $toTable ? \chr($tmp) : $toTable[$tmp + 256];
        }

        return $result;
    }

    private static function tableFor(string $code, bool $isSource, ?Frame $frame): ?string
    {
        if ('' === $code) {
            return null;
        }

        switch (\strtoupper($code[0])) {
            case 'W':
                return CyrConvertTables::CYR_WIN1251;
            case 'A':
            case 'D':
                return CyrConvertTables::CYR_CP866;
            case 'I':
                return CyrConvertTables::CYR_ISO88595;
            case 'M':
                return CyrConvertTables::CYR_MAC;
            case 'K':
                return null;
            default:
                self::warnUnknownCharset($code[0], $isSource, $frame);

                return null;
        }
    }

    private static function warnUnknownCharset(string $code, bool $isSource, ?Frame $frame): void
    {
        if (null === $frame || null === $frame->vmContext) {
            return;
        }

        $label = $isSource ? 'source' : 'destination';
        $frame->vmContext->errors->triggerError(
            \sprintf('Unknown %s charset: %s', $label, $code[0] ?? $code),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
