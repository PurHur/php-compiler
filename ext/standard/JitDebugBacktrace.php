<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\StringDebugBacktrace;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Value;

/** LLVM lowering for debug_backtrace() (#1378, #1056, #3626). */
final class JitDebugBacktrace
{
    /** @return Value */
    public static function invoke(Context $context, ?JITVariable $optionsArg = null): Value
    {
        StringDebugBacktrace::ensureLinked($context);

        $block = $context->jitEnclosingBlock;
        $file0 = $block instanceof Block
            ? self::scriptFileValue($context, $block)
            : $context->builder->load($context->constantStringFromString(''));

        $fn0 = $context->builder->load($context->constantStringFromString('debug_backtrace'));

        $hasFrame1 = $context->getTypeFromString('int1')->constInt(0, false);
        $file1 = $context->builder->load($context->constantStringFromString(''));
        $fn1 = $context->builder->load($context->constantStringFromString(''));

        if ($block instanceof Block && null !== $block->func) {
            $function = $block->func->getScopedName();
            if ('' === $function) {
                $function = $block->func->name ?? '';
            }
            if ('' !== $function) {
                $hasFrame1 = $context->getTypeFromString('int1')->constInt(1, false);
                $file1 = self::scriptFileValue($context, $block);
                $fn1 = $context->builder->load($context->constantStringFromString($function));
            }
        }

        unset($optionsArg);

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_jit_debug_backtrace'),
            $file0,
            $fn0,
            $file1,
            $fn1,
            $hasFrame1
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );

        return $ptr;
    }

    private static function scriptFileValue(Context $context, Block $block): Value
    {
        return $context->builder->load(
            $context->constantStringFromString(
                ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE)
            )
        );
    }
}
