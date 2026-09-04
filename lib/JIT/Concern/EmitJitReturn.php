<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPTypes\Type;
use PHPLLVM;

/**
 * JIT return-value emission helpers (#36403).
 */
trait EmitJitReturn
{
    private function emitJitReturnFromValue(PHPLLVM\Value $func, Block $block, Variable $value): void
    {
        $builder = $this->context->builder;
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'jit_return_from_value_cont');
        $returnBlock = $builder->getInsertBlock();
        $builder->positionAtEnd($returnBlock);
        $this->markJitThisConstructedIfLeavingConstruct($block);
        if (
            0 === $this->context->inlineIncludeDepth
            && JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $block, false, $value)
        ) {
            return;
        }
        if ($this->context->inlineIncludeDepth > 0) {
            JIT\BasicBlockHelper::unsealAndContinue($this->context);
            $returnBlock = $builder->getInsertBlock();
            $builder->positionAtEnd($returnBlock);
            if ([] !== $this->context->inlineIncludeReturnHolders) {
                $holder = $this->context->inlineIncludeReturnHolders[
                    array_key_last($this->context->inlineIncludeReturnHolders)
                ];
                $holderOp = $this->context->inlineIncludeReturnOperands[
                    array_key_last($this->context->inlineIncludeReturnOperands)
                ];
                $value->addref();
                $this->context->setVariableOp($holderOp, $holder);
                $this->assignOperand($holderOp, $value, true);
            } elseif ([] !== $this->context->inlineIncludeReturnOperands) {
                $holderOp = $this->context->inlineIncludeReturnOperands[
                    array_key_last($this->context->inlineIncludeReturnOperands)
                ];
                $value->addref();
                $this->assignOperand($holderOp, $value, true);
            }
            $this->context->inlineIncludeExitBlock = $returnBlock;

            return;
        }
        if ($block->returnTypeVoid) {
            JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
            JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
            JIT\Builtin\TypeErrorRaise::emitRaise(
                $this->context,
                'A void function must not return a value'
            );

            return;
        }
        if ($this->shouldFreeDeadVariablesBeforeBranch()) {
            $this->context->freeDeadVariables($func, $returnBlock, $block);
        }
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();

            return;
        }
        if ($this->cfgFunctionReturnsByRef($block->func)) {
            $value->addref();
            $builder->returnValue(
                JIT\JitValueBox::valuePtrFromVariable($this->context, $value)
            );

            return;
        }
        $value->addref();
        if (null !== $block->returnDnfConstraints
            && !JIT\ClassReturnCheck::generatorSkipsBodyReturnCheck($block)
        ) {
            JIT\DnfParamCheck::enforce(
                $this->context,
                $value,
                $block->returnDnfConstraints,
                'Return value',
                $this->jitReturnTypeCallableName($block)
            );
        }
        if (!$this->emitJitClassReturnTypeCheck($block, $value)) {
            return;
        }
        if (!$this->emitJitScalarReturnTypeCheck($block, $value)) {
            return;
        }
        $expected = $this->effectiveReturnCallbackType($block->func);
        $retval = $this->coerceReturnValue($value, $this->context->helper->loadValue($value), $expected);
        $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
        $builder->returnValue($retval);
    }
}
