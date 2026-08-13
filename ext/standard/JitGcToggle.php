<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GcToggleRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gc_enable/gc_disable/gc_enabled() via GcToggleJitHelper PHP (#3209, #9577). */
final class JitGcToggle
{
    /** @return Value */
    public static function enable(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'gc_enable() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        GcToggleRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('phpc_gc_enable'));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    /** @return Value */
    public static function disable(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'gc_disable() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        GcToggleRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('phpc_gc_disable'));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    public static function isEnabled(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            // Catchable ArgumentCountError (#30653); dummy int1 matches success-path icmp.
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'gc_enabled() expects exactly 0 arguments, '.$argc.' given'
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        GcToggleRuntime::ensureLinked($context);
        $enabled = $context->builder->call($context->lookupFunction('phpc_gc_is_enabled'));
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_NE, $enabled, $i32->constInt(0, false));
    }
}
