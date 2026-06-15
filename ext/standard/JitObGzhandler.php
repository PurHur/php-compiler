<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObGzhandler;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_gzhandler() (ext/zlib/zlib.c, issue #4655, #8818). */
final class JitObGzhandler
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ob_gzhandler() expects 1 or 2 arguments in this compiler build');
        }
        ObGzhandler::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $mode = $i64->constInt(\PHP_OUTPUT_HANDLER_CONT, false);
        if ($argc >= 2) {
            $mode = JitStrictIntArg::lower($context, $args[1], 'ob_gzhandler', 2, 'mode');
        }
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_ob_gzhandler'),
            JitStringBuiltinArg::lower($context, $args[0], 'ob_gzhandler', 0, 'data'),
            $mode
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $result
        );

        return $ptr;
    }
}
