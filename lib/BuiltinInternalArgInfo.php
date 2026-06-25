<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPTypes\InternalArgInfo;

/**
 * php-src internal function arginfo arity (ext/* arginfo tables via ircmaxell/php-types).
 *
 * Used when {@see BuiltinParamNames} has no explicit entry (#11453, ext/reflection/php_reflection.c).
 */
final class BuiltinInternalArgInfo
{
    private static ?InternalArgInfo $argInfo = null;

    public static function paramCountForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }

        return \count($info['params']);
    }

    private static function instance(): InternalArgInfo
    {
        return self::$argInfo ??= new InternalArgInfo();
    }
}
