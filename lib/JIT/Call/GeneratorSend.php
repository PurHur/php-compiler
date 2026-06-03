<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
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
        $genVar = $args[0];
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $failBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('gen_send_closed');
        $okBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('gen_send_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $done, $i1->constInt(0, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        ErrorRaise::emitRaise($context, 'Cannot send to a closed generator');
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
        $pendingField = $context->builder->structGep($statePtr, $map['pending_send']);
        if (count($args) >= 2) {
            GeneratorHelper::assignValueField($context, $pendingField, $args[1], null);
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $pendingField)
            );
        }
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($statePtr, $map['has_pending_send']));

        return GeneratorHelper::resumeAndBoxYield($context, $genVar);
    }
}
