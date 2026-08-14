<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Generator::key(): mixed — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorKey implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::key() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30907)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'Generator::key() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'gen_key_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $genVar = $args[0];
        GeneratorHelper::ensureStarted($context, $genVar);
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $keySlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $keySlot,
            $context->builder->structGep($statePtr, $map['current_key'])
        );
        $has = $context->builder->icmp(Builder::INT_NE, $hasCurrent, $i1->constInt(0, false));

        return $context->builder->select(
            $has,
            JitValueBox::pointer($context, $keySlot),
            JitValueBox::pointer($context, $nullSlot)
        );
    }
}
