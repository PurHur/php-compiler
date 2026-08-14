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

/** Generator::next(): void — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorNext implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::next() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30907)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'Generator::next() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'gen_next_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $genVar = $args[0];
        GeneratorHelper::ensureStarted($context, $genVar);
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $resumeBlock = $fn->appendBasicBlock('gen_next_resume');
        $doneBlock = $fn->appendBasicBlock('gen_next_done');
        $mergeBlock = $fn->appendBasicBlock('gen_next_merge');
        $context->builder->branchIf(
            $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $done, $i1->constInt(0, false)),
            $resumeBlock,
            $doneBlock
        );
        $context->builder->positionAtEnd($resumeBlock);
        GeneratorHelper::runSingleResume($context, GeneratorHelper::resolveResumeLc($context, $genVar), $statePtr);
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($doneBlock);
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
