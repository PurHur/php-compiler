<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_implicit_flush() (issue #3401). */
final class JitObImplicitFlush
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('ob_implicit_flush() accepts at most one argument in this compiler build');
        }
        if (isset($args[0])) {
            $enable = JitBoolArg::lower(
                $context,
                $args[0],
                'ob_implicit_flush(): Argument #1 ($enable)'
            );
        } else {
            $enable = $context->constantFromBool(true);
        }
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_implicit_flush'),
            $context->builder->zExt($enable, $i32)
        );

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
