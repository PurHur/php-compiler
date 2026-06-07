<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op\Expr\Closure;

/**
 * Compile-time checks for PHP 8.2 readonly functions/closures (#7428).
 *
 * php-src: Zend/zend_compile.c — reject mutable use() captures in readonly closures.
 */
final class ReadonlyFunctionCompileCheck
{
    public const MUTABLE_CAPTURE_MESSAGE = 'Cannot bind non-readonly variable $%s in readonly closure';

    public static function isReadonlyFunc(?CfgFunc $func): bool
    {
        if (null === $func) {
            return false;
        }

        return 0 !== (((int) ($func->flags ?? 0)) & self::readonlyFlagMask());
    }

    private static function readonlyFlagMask(): int
    {
        if (!\defined(CfgFunc::class.'::FLAG_READONLY')) {
            return 0;
        }

        return CfgFunc::FLAG_READONLY;
    }

    public static function assertClosureCaptures(Closure $closure): void
    {
        $func = $closure->func;
        if (!self::isReadonlyFunc($func)) {
            return;
        }
        foreach ($closure->useVars as $useVar) {
            $nameOperand = $useVar->name ?? null;
            $name = null;
            if ($nameOperand instanceof \PHPCfg\Operand\Literal && \is_string($nameOperand->value)) {
                $name = $nameOperand->value;
            }
            if (null === $name || '' === $name) {
                continue;
            }
            if ($useVar->byRef) {
                throw new \CompileError(sprintf(self::MUTABLE_CAPTURE_MESSAGE, $name));
            }
            throw new \CompileError(sprintf(self::MUTABLE_CAPTURE_MESSAGE, $name));
        }
    }
}
