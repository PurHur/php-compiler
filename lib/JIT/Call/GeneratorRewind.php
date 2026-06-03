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

/** Generator::rewind(): void — JIT (#4558, Zend/zend_generators.c). */
final class GeneratorRewind implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Generator::rewind() called without $this');
        }
        $genVar = $args[0];
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $resumeIp = $context->builder->load($context->builder->structGep($statePtr, $map['resume_ip']));
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $started = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $resumeIp, $zero),
            $context->builder->icmp(Builder::INT_NE, $hasCurrent, $i1->constInt(0, false))
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $okBlock = $fn->appendBasicBlock('gen_rewind_ok');
        $failBlock = $fn->appendBasicBlock('gen_rewind_fail');
        $context->builder->branchIf($started, $failBlock, $okBlock);
        $context->builder->positionAtEnd($failBlock);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'Exception',
            'Cannot rewind a generator that was already run'
        );
        $context->builder->positionAtEnd($okBlock);
        GeneratorHelper::runSingleResume($context, GeneratorHelper::resolveResumeLc($context, $genVar), $statePtr);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
