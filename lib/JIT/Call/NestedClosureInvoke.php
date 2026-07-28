<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\VmClosure;
use PHPLLVM\Value;

/**
 * NestedJIT invoke for thin-AOT Closures via {@see RuntimeIndirectClosureCall} (#24156).
 *
 * AOT Closures carry {@see VmClosure::TARGET_PROPERTY}, not {@see \PHPCompiler\VM\ObjectEntry::$closureState}.
 * Static method proxy: {@see \PHPCompiler\ext\standard\VmClosureCall::invokeVariable}.
 * Always returns `__value__*` so NestedJIT can materialize a Variable result.
 */
final class NestedClosureInvoke implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('NestedClosureInvoke requires a Closure Variable (#24156)');
        }
        $closure = $args[0];
        $invokeArgs = \array_slice($args, 1);
        $candidates = VmClosure::closureCandidates($context);
        if ([] === $candidates) {
            throw new \LogicException(
                'NestedClosureInvoke: no Closure candidates in module (#24156 / __closure_target)'
            );
        }
        $classId = $context->type->object->lookup('Closure');
        $indirect = new RuntimeIndirectClosureCall($closure, $candidates, $classId);
        $raw = $indirect->call($context, ...$invokeArgs);

        return self::asValuePtr($context, $raw);
    }

    private static function asValuePtr(Context $context, Value $raw): Value
    {
        $have = $context->getStringFromType($raw->typeOf());
        if ('__value__*' === $have) {
            return $raw;
        }
        if ('__value__' === $have) {
            return JitValueBox::coerceToValuePtrForStore($context, $raw);
        }
        // Scalar / object / hashtable returns from typed Closures — box into Variable shape.
        BasicBlockHelper::ensureOpenInsertBlock($context, 'nested_closure_invoke_box');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if ('int64' === $have || 'int32' === $have || 'int1' === $have) {
            $long = 'int64' === $have
                ? $raw
                : $context->builder->zExt($raw, $context->getTypeFromString('int64'));
            $context->builder->call($context->lookupFunction('__value__writeLong'), $ptr, $long);

            return $ptr;
        }
        if ('double' === $have) {
            $context->builder->call($context->lookupFunction('__value__writeDouble'), $ptr, $raw);

            return $ptr;
        }
        if ('__string__*' === $have) {
            $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $raw);

            return $ptr;
        }
        if ('__object__*' === $have) {
            $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $raw);

            return $ptr;
        }
        if ('__hashtable__*' === $have) {
            $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $raw);

            return $ptr;
        }

        return JitValueBox::coerceToValuePtrForStore($context, $raw);
    }
}
