<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for unpack() via __compiler_unpack (issue #3188). */
final class JitUnpack
{
    public static function unpack(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('unpack() requires two or three arguments in this compiler build');
        }
        $fmt = JitStringArg::lower($context, $args[0], 'unpack() format');
        $data = JitStringArg::lower($context, $args[1], 'unpack() data');
        $offset = $context->getTypeFromString('int64')->constInt(0, false);
        if (3 === $argc) {
            $offset = self::jitOffsetArg($context, $args[2]);
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

    private static function jitOffsetArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('unpack() offset must be an integer in this compiler build');
    }
}
