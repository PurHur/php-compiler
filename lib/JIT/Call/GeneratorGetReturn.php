<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Generator::getReturn(): mixed — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorGetReturn implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::getReturn() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30866)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'Generator::getReturn() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'gen_getreturn_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $genVar = $args[0];
        GeneratorHelper::ensureStarted($context, $genVar);
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $hasReturned = $context->builder->load($context->builder->structGep($statePtr, $map['has_returned']));
        $okBlock = BasicBlockHelper::append($context, 'gen_getreturn_ok');
        $failBlock = BasicBlockHelper::append($context, 'gen_getreturn_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hasReturned, $i1->constInt(0, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        ErrorRaise::emitRaise(
            $context,
            "Cannot get return value of a generator that hasn't returned"
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            $context->builder->structGep($statePtr, $map['return_value'])
        );

        return $slot;
    }
}
