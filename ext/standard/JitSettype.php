<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\SettypeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * settype() JIT lowering — delegates in-place cast to SettypeJitHelper PHP (#3151, #17335).
 *
 * php-src: ext/standard/type.c — php_settype
 * SSOT: {@see VmSettype}, {@see SettypeJitHelper}
 */
final class JitSettype
{
    public static function invoke(Context $context, JITVariable $var, JITVariable $typeArg): Value
    {
        $typeLit = JitStringArg::compileTimeLiteral($typeArg);
        if (null === $typeLit) {
            throw new \LogicException(
                'settype() with a non-constant type name is not supported for JIT in this compiler build'
            );
        }

        $type = strtolower($typeLit);
        if ('resource' === $type) {
            throw new \ValueError('Cannot convert to resource type');
        }

        $canonical = self::canonicalTypeName($type);
        if (null === $canonical) {
            throw new \ValueError('settype(): Argument #2 ($type) must be a valid type');
        }

        $destPtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $var)
        );

        SettypeRuntime::applyInPlace($context, $destPtr, $canonical);

        return $context->constantFromBool(true);
    }

    private static function canonicalTypeName(string $type): ?string
    {
        switch ($type) {
            case 'integer':
            case 'int':
                return 'integer';
            case 'double':
            case 'float':
                return 'double';
            case 'bool':
            case 'boolean':
                return 'boolean';
            case 'string':
            case 'array':
            case 'null':
            case 'object':
                return $type;
            default:
                return null;
        }
    }
}
