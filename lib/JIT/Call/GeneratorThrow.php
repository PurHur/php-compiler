<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Generator::throw(Throwable $exception): mixed — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorThrow implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::throw() called without $this');
        }
        if (count($args) < 2) {
            throw new \LogicException('Generator::throw() requires an exception argument');
        }
        $genVar = $args[0];
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $resumeIp = $context->builder->load($context->builder->structGep($statePtr, $map['resume_ip']));
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $okBlock = $fn->appendBasicBlock('gen_throw_ok');
        $closedFail = $fn->appendBasicBlock('gen_throw_closed');
        $uninitFail = $fn->appendBasicBlock('gen_throw_uninit');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $done, $i1->constInt(0, false)),
            $okBlock,
            $closedFail
        );
        $context->builder->positionAtEnd($closedFail);
        TryCatchHelper::emitCatchableClassError($context, 'Exception', 'Cannot throw to a closed generator');
        $context->builder->positionAtEnd($okBlock);
        $started = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $resumeIp, $zero),
            $context->builder->icmp(Builder::INT_NE, $hasCurrent, $i1->constInt(0, false))
        );
        $throwOk = $fn->appendBasicBlock('gen_throw_resume');
        $context->builder->branchIf($started, $throwOk, $uninitFail);
        $context->builder->positionAtEnd($uninitFail);
        TryCatchHelper::emitCatchableClassError($context, 'Exception', 'Cannot throw to an uninitialized generator');
        $context->builder->positionAtEnd($throwOk);
        $exception = $args[1];
        $excObj = $exception->value;
        if (Variable::TYPE_OBJECT !== $exception->type) {
            if (Variable::TYPE_VALUE === $exception->type) {
                $excObj = $context->builder->call(
                    $context->lookupFunction('__value__readObject'),
                    JitValueBox::valuePtrFromVariable($context, $exception)
                );
            } else {
                TryCatchHelper::emitCatchableClassError(
                    $context,
                    'TypeError',
                    'Generator::throw(): Argument #1 ($exception) must be of type Throwable, '
                    .Variable::getStringType($exception->type).' given'
                );
                $excObj = $context->getTypeFromString('__object__*')->constNull();
            }
        }
        $pendingField = $context->builder->structGep($statePtr, $map['pending_throw']);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $pendingField),
            $excObj
        );
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($statePtr, $map['has_pending_throw']));

        return GeneratorHelper::resumeAndBoxYield($context, $genVar);
    }
}
