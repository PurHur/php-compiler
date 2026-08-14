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
use PHPLLVM\Value;

/** Generator::valid(): bool — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorValid implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::valid() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30907)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'Generator::valid() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'gen_valid_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $genVar = $args[0];
        GeneratorHelper::ensureStarted($context, $genVar);
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $hasReturned = $context->builder->load($context->builder->structGep($statePtr, $map['has_returned']));
        $resumeIp = $context->builder->load($context->builder->structGep($statePtr, $map['resume_ip']));
        $notDone = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $done, $i1->constInt(0, false));
        $notReturned = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $hasReturned, $i1->constInt(0, false));
        $active = $context->builder->or(
            $context->builder->icmp(\PHPLLVM\Builder::INT_NE, $hasCurrent, $i1->constInt(0, false)),
            $context->builder->icmp(\PHPLLVM\Builder::INT_NE, $resumeIp, $zero)
        );
        $valid = $context->builder->and($notDone, $context->builder->and($notReturned, $active));
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            JitValueBox::pointer($context, $slot),
            $context->builder->zext($valid, $context->getTypeFromString('int64'))
        );

        return $slot;
    }
}
