<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;

/** Minimal ini_set() subset (issue #1374): error_reporting, display_errors, memory_limit. */
final class VmIni
{
    /** @var list<string> */
    public const SUPPORTED_KEYS = ['error_reporting', 'display_errors', 'memory_limit'];

    public static function set(Context $ctx, string $option, string $newValue): string|false
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        return match ($key) {
            'error_reporting' => self::setErrorReporting($ctx, $newValue),
            'display_errors' => self::setDisplayErrors($ctx, $newValue),
            'memory_limit' => self::setMemoryLimit($newValue),
            default => false,
        };
    }

    private static function setErrorReporting(Context $ctx, string $newValue): string|false
    {
        $old = (string) $ctx->errors->getErrorReporting();
        $ctx->errors->setErrorReporting(self::parseErrorReporting($newValue));

        return $old;
    }

    private static function setDisplayErrors(Context $ctx, string $newValue): string|false
    {
        $old = $ctx->errors->getDisplayErrors() ? '1' : '0';
        $ctx->errors->setDisplayErrors(self::parseBoolIni($newValue));

        return $old;
    }

    private static function setMemoryLimit(string $newValue): string|false
    {
        if ('-1' === $newValue) {
            return false;
        }
        $old = ini_get('memory_limit');
        if (false === ini_set('memory_limit', $newValue)) {
            return false;
        }

        return false !== $old ? $old : '';
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
