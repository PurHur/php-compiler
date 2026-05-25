<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;

/** Minimal ini_set() subset (issue #1374): error_reporting, display_errors, memory_limit. */
final class VmIni
{
    /** @var list<string> */
    public const SUPPORTED_KEYS = ['error_reporting', 'display_errors', 'memory_limit'];

    public static function set(Context $ctx, string $option, string $newValue) {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        switch ($key) {
            case 'error_reporting':
                return self::setErrorReporting($ctx, $newValue);
            case 'display_errors':
                return self::setDisplayErrors($ctx, $newValue);
            case 'memory_limit':
                return self::setMemoryLimit($newValue);
            default:
                return false;
        }
    }

    /** @return string|false */
    public static function get(Context $ctx, string $option) {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        switch ($key) {
            case 'error_reporting':
                return (string) $ctx->errors->getErrorReporting();
            case 'display_errors':
                return $ctx->errors->getDisplayErrors() ? '1' : '0';
            case 'memory_limit':
                return self::$memoryLimit;
            default:
                return false;
        }
    }

    private static string $memoryLimit = '128M';

    private static function setErrorReporting(Context $ctx, string $newValue) {
        $old = (string) $ctx->errors->getErrorReporting();
        $ctx->errors->setErrorReporting(self::parseErrorReporting($newValue));

        return $old;
    }

    private static function setDisplayErrors(Context $ctx, string $newValue) {
        $old = $ctx->errors->getDisplayErrors() ? '1' : '0';
        $ctx->errors->setDisplayErrors(self::parseBoolIni($newValue));

        return $old;
    }

    private static function setMemoryLimit(string $newValue) {
        if ('-1' === $newValue) {
            return false;
        }
        $old = self::$memoryLimit;
        self::$memoryLimit = $newValue;

        return $old;
    }

    public static function parseErrorReporting(string $value): int
    {
        $trimmed = trim($value);

        return '' === $trimmed ? 0 : (int) $trimmed;
    }

    public static function parseBoolIni(string $value): bool
    {
        $trimmed = strtolower(trim($value));

        return !('' === $trimmed || '0' === $trimmed || 'off' === $trimmed || 'false' === $trimmed);
    }
}
