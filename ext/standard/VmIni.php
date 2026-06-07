<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;

/** Minimal ini_set() subset (issue #1374): error_reporting, display_errors, memory_limit, serialize_precision. */
final class VmIni
{
    /** @var list<string> */
    public const SUPPORTED_KEYS = [
        'error_reporting',
        'display_errors',
        'memory_limit',
        'serialize_precision',
    ];

    private const CFG_ERROR_REPORTING = '32767';

    private const CFG_DISPLAY_ERRORS = '1';

    private const CFG_MEMORY_LIMIT = '128M';

    private const CFG_SERIALIZE_PRECISION = '-1';

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
            case 'serialize_precision':
                return self::setSerializePrecision($newValue);
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
            case 'serialize_precision':
                return (string) self::$serializePrecision;
            default:
                return false;
        }
    }

    /** get_cfg_var() — php.ini compile-time values (ext/standard/ini.c, #6119). */
    public static function getCfgVar(string $option): string|false
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        return match ($key) {
            'error_reporting' => self::CFG_ERROR_REPORTING,
            'display_errors' => self::CFG_DISPLAY_ERRORS,
            'memory_limit' => self::CFG_MEMORY_LIMIT,
            'serialize_precision' => self::CFG_SERIALIZE_PRECISION,
            default => false,
        };
    }

    /** php-src PG(serialize_precision) default -1 (zend_dtoa mode 0; issue #7100). */
    public static function getSerializePrecision(): string
    {
        return (string) self::$serializePrecision;
    }

    private static string $memoryLimit = self::CFG_MEMORY_LIMIT;

    private static int $serializePrecision = -1;

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

    private static function setSerializePrecision(string $newValue) {
        $old = (string) self::$serializePrecision;
        self::$serializePrecision = self::parseSerializePrecision($newValue);

        return $old;
    }

    public static function parseSerializePrecision(string $value): int
    {
        $trimmed = trim($value);

        return '' === $trimmed ? -1 : (int) $trimmed;
    }

    public static function errorReporting(Context $ctx, ?int $newLevel = null): int
    {
        $old = $ctx->errors->getErrorReporting();
        if (null !== $newLevel) {
            $ctx->errors->setErrorReporting($newLevel);
        }

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
