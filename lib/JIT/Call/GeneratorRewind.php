<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\GeneratorState;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Generator::rewind(): void — JIT (#4558, #23713, Zend/zend_generators.c). */
final class GeneratorRewind implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::rewind() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#31034)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'Generator::rewind() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'gen_rewind_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $genVar = $args[0];
        // zend_generator_rewind: ensure_initialized, then require AT_FIRST_YIELD (#23713).
        GeneratorHelper::ensureStarted($context, $genVar);
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $atFirst = $context->builder->load($context->builder->structGep($statePtr, $map['at_first_yield']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $okBlock = $fn->appendBasicBlock('gen_rewind_ok');
        $failBlock = $fn->appendBasicBlock('gen_rewind_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $atFirst, $i1->constInt(0, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'Exception',
            GeneratorState::REWIND_ALREADY_RUN_ERROR
        );
        $context->builder->positionAtEnd($okBlock);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
