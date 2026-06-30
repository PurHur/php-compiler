<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\UnpackJitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for unpack() via __compiler_unpack (issue #3188, #5442). */
final class JitUnpack
{
    public static function unpack(Context $context, JITVariable ...$args): Value
    {
        UnpackJitRuntime::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('unpack() requires two or three arguments in this compiler build');
        }
        $fmt = JitStringBuiltinArg::lower($context, $args[0], 'unpack', 0, 'format');
        $data = JitStringBuiltinArg::lower($context, $args[1], 'unpack', 1, 'string');
        $offset = $context->getTypeFromString('int64')->constInt(0, false);
        if (3 === $argc) {
            $offset = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'unpack', 3, 'offset');
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_unpack'),
            $fmt,
            $data,
            $offset,
            $ptr
        );

        return $ptr;
    }

}
