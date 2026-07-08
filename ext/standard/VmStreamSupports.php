<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Variable;

/**
 * stream_supports() feature codes — php-src main/php_streams.h (STREAM_META_*)
 * plus compiler feature probes (STREAM_LOCK, STREAM_FILTER).
 */
final class VmStreamSupports
{
    public const STREAM_META_TOUCH = 1;
    public const STREAM_META_OWNER_NAME = 2;
    public const STREAM_META_OWNER = 3;
    public const STREAM_META_GROUP_NAME = 4;
    public const STREAM_META_GROUP = 5;
    public const STREAM_META_ACCESS = 6;
    public const STREAM_LOCK = 7;
    /** PHP 8.4+ stream_supports() seek probe (php-src main/php_streams.h PHP_STREAM_META_SEEKABLE). */
    public const STREAM_META_SEEKABLE = 8;
    public const STREAM_FILTER = 8;

    /** PHP 8.3+ stream_supports() capability probes (issue #11702). */
    public const STREAM_SUPPORT_LOCK = self::STREAM_LOCK;
    public const STREAM_SUPPORT_SEEK = self::STREAM_META_SEEKABLE;
    public const STREAM_SUPPORT_TELL = 9;

    /** PHP 8.4+ string feature names (php-src ext/standard/streams.c; issue #16329). */
    public const STREAM_SUPPORT_READ = 10;
    public const STREAM_SUPPORT_WRITE = 11;

    /** @var array<string, int> php-src stream_supports() string feature map (PHP 8.4). */
    private const FEATURE_NAME_MAP = [
        'lock' => self::STREAM_LOCK,
        'seek' => self::STREAM_META_SEEKABLE,
        'seekable' => self::STREAM_META_SEEKABLE,
        'tell' => self::STREAM_SUPPORT_TELL,
        'read' => self::STREAM_SUPPORT_READ,
        'write' => self::STREAM_SUPPORT_WRITE,
        'filter' => self::STREAM_FILTER,
    ];

    /**
     * Resolve stream_supports() $feature — int legacy codes or PHP 8.4 string names.
     *
     * @return int|null feature int code, or null when the string is unknown (caller returns false)
     *
     * @throws \TypeError when the operand is neither int nor string
     */
    public static function resolveFeatureVariable(
        Variable $var,
        string $fn = 'stream_supports',
        int $argNum = 2,
        string $paramName = 'feature'
    ): ?int {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_STRING === $var->type) {
            return self::resolveFeatureFromString($var->toString());
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type string|int, %s given',
            $fn,
            $argNum,
            $paramName,
            VmStreamArg::debugTypeName($var)
        ));
    }

    /** @return int|null null when the feature name is unknown */
    public static function resolveFeatureFromString(string $feature): ?int
    {
        if ('' === $feature) {
            return null;
        }
        $lower = \strtolower($feature);
        if (isset(self::FEATURE_NAME_MAP[$lower])) {
            return self::FEATURE_NAME_MAP[$lower];
        }
        if (\is_numeric($feature)) {
            return (int) $feature;
        }

        return null;
    }

    /** @return array<string, int> */
    public static function constants(): array
    {
        $constants = [
            'STREAM_META_TOUCH' => self::STREAM_META_TOUCH,
            'STREAM_META_OWNER_NAME' => self::STREAM_META_OWNER_NAME,
            'STREAM_META_OWNER' => self::STREAM_META_OWNER,
            'STREAM_META_GROUP_NAME' => self::STREAM_META_GROUP_NAME,
            'STREAM_META_GROUP' => self::STREAM_META_GROUP,
            'STREAM_META_ACCESS' => self::STREAM_META_ACCESS,
            'STREAM_LOCK' => self::STREAM_LOCK,
            'STREAM_META_SEEKABLE' => self::STREAM_META_SEEKABLE,
            'STREAM_FILTER' => self::STREAM_FILTER,
        ];
        if (CompilerVersion::supportsStreamSupports()) {
            $constants['STREAM_SUPPORT_LOCK'] = self::STREAM_SUPPORT_LOCK;
            $constants['STREAM_SUPPORT_SEEK'] = self::STREAM_SUPPORT_SEEK;
            $constants['STREAM_SUPPORT_TELL'] = self::STREAM_SUPPORT_TELL;
        }
        if (CompilerVersion::supportsStreamSupportReadWriteConstants()) {
            $constants['STREAM_SUPPORT_READ'] = self::STREAM_SUPPORT_READ;
            $constants['STREAM_SUPPORT_WRITE'] = self::STREAM_SUPPORT_WRITE;
        }

        return $constants;
    }
}
