<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
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
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (1 arg); $args[0] is $this (#30866)
        $userArgCount = \count($args) - 1;
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'Generator::throw() expects exactly 1 argument, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'gen_throw_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $genVar = $args[0];
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $okBlock = $fn->appendBasicBlock('gen_throw_ok');
        $closedBlock = $fn->appendBasicBlock('gen_throw_closed');
        $throwOk = $fn->appendBasicBlock('gen_throw_resume');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $done, $i1->constInt(0, false)),
            $okBlock,
            $closedBlock
        );
        $exception = $args[1];
        $excObj = $this->resolveThrowableObject($context, $exception);
        $context->builder->positionAtEnd($closedBlock);
        $this->emitClosedGeneratorThrowInCallerContext($context, $excObj);
        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($throwOk);
        $context->builder->positionAtEnd($throwOk);
        $pendingField = $context->builder->structGep($statePtr, $map['pending_throw']);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $pendingField),
            $excObj
        );
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($statePtr, $map['has_pending_throw']));

        return GeneratorHelper::resumeAndBoxYield($context, $genVar);
    }

    /** Zend Generator::throw() else branch: closed generator throws in caller context (#10414). */
    private function emitClosedGeneratorThrowInCallerContext(Context $context, Value $excObj): void
    {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $excObj);
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        // Only branch when dispatch lives in this function — a leaked generator-resume
        // handler would create a cross-function `br` and fail module verify (#27518).
        if (null !== $handler && null !== $handler->dispatchBb) {
            $insert = $context->builder->getInsertBlock();
            $parent = null !== $insert ? $insert->getParent() : null;
            $dispatchParent = $handler->dispatchBb->getParent();
            if (
                $parent instanceof \PHPLLVM\Value\Function_
                && $dispatchParent instanceof \PHPLLVM\Value\Function_
                && TryCatchHelper::sameLlvmFunction($parent, $dispatchParent)
            ) {
                $context->builder->branch($handler->dispatchBb);

                return;
            }
        }
        $context->builder->call($context->lookupFunction('abort'));
    }

    private function resolveThrowableObject(Context $context, Variable $exception): Value
    {
        if (Variable::TYPE_OBJECT === $exception->type) {
            return $exception->value;
        }
        if (Variable::TYPE_VALUE === $exception->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $exception)
            );
        }
        TryCatchHelper::emitCatchableClassError(
            $context,
            'TypeError',
            'Generator::throw(): Argument #1 ($exception) must be of type Throwable, '
            .Variable::getStringType($exception->type).' given'
        );

        return $context->getTypeFromString('__object__*')->constNull();
    }
}
