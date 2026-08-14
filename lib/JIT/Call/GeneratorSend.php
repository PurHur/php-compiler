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

/** Generator::send(mixed $value): mixed — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorSend implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::send() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (1 arg); $args[0] is $this (#30907)
        $userArgCount = \count($args) - 1;
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'Generator::send() expects exactly 1 argument, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'gen_send_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $genVar = $args[0];
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $closedBlock = $fn->appendBasicBlock('gen_send_closed');
        $okBlock = $fn->appendBasicBlock('gen_send_ok');
        $mergeBlock = $fn->appendBasicBlock('gen_send_merge');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $done, $i1->constInt(0, false)),
            $okBlock,
            $closedBlock
        );
        $context->builder->positionAtEnd($closedBlock);
        $closedSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $closedSlot)
        );
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($okBlock);
        $pendingField = $context->builder->structGep($statePtr, $map['pending_send']);
        GeneratorHelper::assignValueField($context, $pendingField, $args[1], null);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($statePtr, $map['has_pending_send']));
        $activeSlot = GeneratorHelper::resumeSendAndBoxYield($context, $genVar);
        // resumeSendAndBoxYield may leave the builder in gen_send_auto_skip, not $okBlock (#23712).
        $afterSendBlock = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming(JitValueBox::pointer($context, $closedSlot), $closedBlock);
        $phi->addIncoming($activeSlot, $afterSendBlock);

        return $phi;
    }
}
