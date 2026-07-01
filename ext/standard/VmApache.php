<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * Apache subprocess environment helpers — CGI/process environ bridge (#11626).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(apache_getenv), PHP_FUNCTION(apache_setenv)
 */
final class VmApache
{
    public static function getenv(string $variable, bool $walkToTop = false): string|false
    {
        unset($walkToTop);

        return VmEnv::getenv($variable);
    }

    public static function setenv(string $variable, string $value, bool $walkToTop = false): bool
    {
        unset($walkToTop);

        return VmEnv::putenv($variable.'='.$value);
    }

    public static function coerceWalkToTopArg(Variable $var, string $function, int $argIndex): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($walk_to_top) must be of type bool, %s given',
            $function,
            $argIndex + 1,
            match ($var->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            }
        ));
    }
}
