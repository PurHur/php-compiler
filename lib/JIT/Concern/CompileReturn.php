<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * RETURN / RETURN_VOID opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_RETURN} and
 * {@code TYPE_RETURN_VOID}. Returns the early-exit basic block (same as the
 * inlined {@code return $origBasicBlock} / {@code return $returnBlock} paths).
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_RETURN / ZEND_RETURN_BY_REF /
 * ZEND_GENERATOR_RETURN), Zend/zend_execute.c — move-only Concern extract;
 * no new C ABI.
 */
trait CompileReturn
{
    /**
     * @return PHPLLVM\BasicBlock early-exit insert block for compileBlockInternal
     */
    private function compileReturnOp(
        Block $block,
        OpCode $op,
        PHPLLVM\Value $func,
        PHPLLVM\Builder $builder,
        PHPLLVM\BasicBlock $origBasicBlock
    ): PHPLLVM\BasicBlock {
        switch ($op->type) {
            case OpCode::TYPE_RETURN_VOID:
                if ($this->context->compilingGeneratorResume) {
                    $stateParam = $this->context->generatorStateParam;
                    assert(null !== $stateParam);
                    $map = $this->context->structFieldMap['__generator_state__'];
                    $i1 = $this->context->getTypeFromString('int1');
                    $i64 = $this->context->getTypeFromString('int64');
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeNull'),
                        \PHPCompiler\JIT\JitValueBox::pointer(
                            $this->context,
                            $this->context->builder->structGep($stateParam, $map['return_value'])
                        )
                    );
                    $this->context->builder->store(
                        $i1->constInt(1, false),
                        $this->context->builder->structGep($stateParam, $map['has_returned'])
                    );
                    $this->context->builder->store(
                        $i1->constInt(1, false),
                        $this->context->builder->structGep($stateParam, $map['done'])
                    );
                    $this->context->builder->store(
                        $i1->constInt(0, false),
                        $this->context->builder->structGep($stateParam, $map['has_current'])
                    );
                    $this->context->builder->returnValue($i64->constInt(0, false));

                    return $origBasicBlock;
                }
                $returnBlock = $builder->getInsertBlock();
                $builder->positionAtEnd($returnBlock);
                $this->markJitThisConstructedIfLeavingConstruct($block);
                $this->releaseJitFunctionLocalsAtReturn($block);
                // #36382: void __construct return must not freeDeadVariables — NEW temps
                // assigned into `$this->prop` are already propertyStore-addref'd; the dead
                // temp dtor then destroys heap props (circular RouteCollector↔RouteParser)
                // and SIGSEGVs after the ctor body (peer TYPE_RETURN addref-before-freeDead
                // for `return $new` / Nyholm Uri::withUserInfo). php-src: zend_execute.c
                // ZEND_ASSIGN to object props keeps the prop root; ctor frame temps that
                // escaped into $this must not be destroyed at ZEND_RETURN / fallthrough.
                if ($this->shouldFreeDeadVariablesBeforeBranch() && !$this->isJitConstructFrame($block)) {
                    $this->context->freeDeadVariables($func, $returnBlock, $block);
                }
                if (
                    0 === $this->context->inlineIncludeDepth
                    && \PHPCompiler\JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $block, true, null)
                ) {
                    return $origBasicBlock;
                }
                if (0 === $this->context->inlineIncludeDepth) {
                    if ($block->returnTypeNever) {
                        $neverFunc = null !== $block->func ? $block->func->name : null;
                        if (null !== $neverFunc && '' !== $neverFunc) {
                            $neverFunc = \PHPCompiler\VM\ParamArgumentCountError::formatUserFunctionName($neverFunc);
                        }
                        \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise(
                            $this->context,
                            null !== $neverFunc && '' !== $neverFunc
                                ? "{$neverFunc}(): never-returning function must not implicitly return"
                                : 'A never-returning function must not return'
                        );
                    } elseif ($this->jitDeclaredReturnTypeRequiresValue($block)) {
                        // php-src zend_verify_return_error — TypeError + "none returned" (#26485, #26486).
                        $expected = \PHPCompiler\VM\TypeCheck::expectedReturnTypeLabelForNoneReturned($block);
                        if ($block->returnTypeStatic && null !== $block->func && null !== $block->func->class) {
                            $className = $block->func->class->value ?? null;
                            if (is_string($className) && '' !== $className) {
                                $expected = $className;
                            }
                        }
                        $callableName = $this->jitReturnTypeCallableName($block);
                        $message = "Return value must be of type {$expected}, none returned";
                        if (null !== $callableName && '' !== $callableName) {
                            $message = "{$callableName}(): {$message}";
                        }
                        \PHPCompiler\JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
                        \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
                        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType) {
                            // Cross-function catchable TypeError (Enum::from pattern, #24219 / #26486).
                            \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureStandaloneBodies($this->context);
                            \PHPCompiler\JIT\TryCatchHelper::emitPendTypeErrorForCaller($this->context, $message);
                            \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise($this->context, $message);
                            \PHPCompiler\JIT\TryCatchHelper::emitPropagateReturnAfterPendingThrow($this->context, $func);

                            return $this->context->inlineIncludeDepth > 0
                                ? $returnBlock
                                : $origBasicBlock;
                        }
                        \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise($this->context, $message);
                    }
                    if ($this->isVoidLlvmFunction($func)) {
                        $this->context->builder->returnVoid();
                    } else {
                        $expectedReturn = null !== $block->func
                            ? $this->cfgFunctionReturnCallbackType($block->func)
                            : null;
                        $this->context->builder->returnValue(
                            null !== $expectedReturn
                                ? $this->defaultLlvmReturnValueForCallbackType($expectedReturn, $func)
                                : $this->defaultLlvmReturnValue($func)
                        );
                    }
                } else {
                    $this->context->inlineIncludeExitBlock = $returnBlock;
                }

                return $this->context->inlineIncludeDepth > 0
                    ? $returnBlock
                    : $origBasicBlock;
            case OpCode::TYPE_RETURN:
                if ($this->context->compilingGeneratorResume) {
                    $stateParam = $this->context->generatorStateParam;
                    assert(null !== $stateParam);
                    $map = $this->context->structFieldMap['__generator_state__'];
                    $i1 = $this->context->getTypeFromString('int1');
                    $i64 = $this->context->getTypeFromString('int64');
                    $returnOperand = $block->getOperand($op->arg1);
                    if (null !== $returnOperand) {
                        $return = $this->context->getVariableFromOp($returnOperand);
                        $this->assignValueToGeneratorField(
                            $this->context->builder->structGep($stateParam, $map['return_value']),
                            $return,
                            $returnOperand
                        );
                    } else {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__writeNull'),
                            \PHPCompiler\JIT\JitValueBox::pointer(
                                $this->context,
                                $this->context->builder->structGep($stateParam, $map['return_value'])
                            )
                        );
                    }
                    $this->context->builder->store(
                        $i1->constInt(1, false),
                        $this->context->builder->structGep($stateParam, $map['has_returned'])
                    );
                    $this->context->builder->store(
                        $i1->constInt(1, false),
                        $this->context->builder->structGep($stateParam, $map['done'])
                    );
                    $this->context->builder->store(
                        $i1->constInt(0, false),
                        $this->context->builder->structGep($stateParam, $map['has_current'])
                    );
                    $this->context->builder->returnValue($i64->constInt(0, false));

                    return $origBasicBlock;
                }
                $returnOperand = $block->getOperand($op->arg1);
                $return = $this->context->getVariableFromOp($returnOperand);
                $this->recordFunctionReturnedClosureCall($block, $return);
                $this->markJitThisConstructedIfLeavingConstruct($block);
                if (
                    0 === $this->context->inlineIncludeDepth
                    && \PHPCompiler\JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $block, false, $return)
                ) {
                    return $origBasicBlock;
                }
                if ($this->context->inlineIncludeDepth > 0) {
                    \PHPCompiler\JIT\BasicBlockHelper::unsealAndContinue($this->context);
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    if ([] !== $this->context->inlineIncludeReturnHolders) {
                        $holder = $this->context->inlineIncludeReturnHolders[
                            array_key_last($this->context->inlineIncludeReturnHolders)
                        ];
                        $holderOp = $this->context->inlineIncludeReturnOperands[
                            array_key_last($this->context->inlineIncludeReturnOperands)
                        ];
                        $return->addref();
                        $this->context->setVariableOp($holderOp, $holder);
                        $this->assignOperand($holderOp, $return, true);
                    } elseif ([] !== $this->context->inlineIncludeReturnOperands) {
                        $holderOp = $this->context->inlineIncludeReturnOperands[
                            array_key_last($this->context->inlineIncludeReturnOperands)
                        ];
                        $return->addref();
                        $this->assignOperand($holderOp, $return, true);
                    }
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    $this->context->inlineIncludeExitBlock = $returnBlock;

                    return $returnBlock;
                }
                $returnBlock = $builder->getInsertBlock();
                $builder->positionAtEnd($returnBlock);
                if ($block->returnTypeNever) {
                    $neverFunc = null !== $block->func ? $block->func->name : null;
                    if (null !== $neverFunc && '' !== $neverFunc) {
                        $neverFunc = \PHPCompiler\VM\ParamArgumentCountError::formatUserFunctionName($neverFunc);
                    }
                    \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise(
                        $this->context,
                        null !== $neverFunc && '' !== $neverFunc
                            ? "{$neverFunc}(): never-returning function must not implicitly return"
                            : 'A never-returning function must not return'
                    );
                }
                if ($block->returnTypeVoid) {
                    \PHPCompiler\JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
                    \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
                    \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise(
                        $this->context,
                        'A void function must not return a value'
                    );

                    return $origBasicBlock;
                }
                $returnOperand = $block->getOperand($op->arg1);
                // Keep the return value alive before dead-temp dtor: php-cfg may mark the
                // NEW/clone result (or an alias temp) dead while RETURN still names `$new`.
                // Delref-before-addref destroyed heap props so callers saw NULL / unset after
                // `return $new` / `return clone $this` under AOT (#36382 Nyholm Uri::withUserInfo).
                if (!$this->isVoidLlvmFunction($func) && !$this->cfgFunctionReturnsByRef($block->func)) {
                    $return->addref();
                }
                if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                    // php-cfg may mark inline `new class` temps dead before return (#3098).
                    $this->context->freeDeadVariables($func, $returnBlock, $block, $returnOperand);
                }
                if ($this->isVoidLlvmFunction($func)) {
                    $this->context->builder->returnVoid();
                } elseif ($this->cfgFunctionReturnsByRef($block->func)) {
                    $return->addref();
                    // FETCH_DIM_W orphan → live HT entry before return (#34740 / re-#34733).
                    $this->aliasAssignRefNamedDestToDimEntry($return);
                    // Alias the live cell (static/global/property/HT heap box), not a
                    // stack snapshot — Zend ZEND_RETURN_BY_REF (#34717 / #4054).
                    $this->context->builder->returnValue(
                        \PHPCompiler\JIT\JitValueBox::valuePtrForByRefReturn($this->context, $return)
                    );
                } else {
                    // addref already emitted above (before freeDeadVariables).
                    if ($block->returnTypeStatic) {
                        $objectType = $this->context->type->object;
                        assert($objectType instanceof \PHPCompiler\JIT\Builtin\Type\Object_);
                        \PHPCompiler\JIT\LateStaticBindingHelper::emitAssertStaticReturn(
                            $objectType,
                            $block,
                            $return,
                            $this->jitReturnTypeCallableName($block)
                        );
                    }
                    if (null !== $block->returnDnfConstraints
                        && !\PHPCompiler\JIT\ClassReturnCheck::generatorSkipsBodyReturnCheck($block)
                    ) {
                        \PHPCompiler\JIT\DnfParamCheck::enforce(
                            $this->context,
                            $return,
                            $block->returnDnfConstraints,
                            'Return value',
                            $this->jitReturnTypeCallableName($block)
                        );
                    }
                    if (!$this->emitJitClassReturnTypeCheck($block, $return)) {
                        return $origBasicBlock;
                    }
                    if (!$this->emitJitScalarReturnTypeCheck($block, $return)) {
                        return $origBasicBlock;
                    }
                    $retval = $this->context->helper->loadValue($return);
                    $expected = $this->effectiveReturnCallbackType($block->func);
                    // Zend ZEND_RETURN: ZVAL_COPY (addref) then destroys the CV
                    // (zend_execute.c). freeDeadVariables skips the return operand after
                    // our addref. TYPE_STRING addref works; TYPE_VALUE addref is a no-op
                    // so the skip alone leaks the boxed string under thin AOT (#36388).
                    if ('__string__*' === $expected) {
                        if (Variable::TYPE_STRING === $return->type) {
                            if (Variable::KIND_VARIABLE === $return->kind) {
                                $return->free();
                            } else {
                                $this->context->refcount->delref($retval);
                            }
                        } elseif (Variable::TYPE_VALUE === $return->type) {
                            $str = \PHPCompiler\JIT\JitValueBox::readStringOrNull(
                                $this->context,
                                $return
                            );
                            // Keep the string across valueDelref of the return CV.
                            $this->context->refcount->addref($str);
                            $return->free();
                            $retval = $str;
                            $this->context->builder->returnValue(
                                $this->alignRetvalToLlvmFnReturn($retval, $func)
                            );

                            return $origBasicBlock;
                        }
                    }
                    $retval = $this->coerceReturnValue($return, $retval, $expected);
                    $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
                    $this->context->builder->returnValue($retval);
                }
    
                return $origBasicBlock;

            default:
                throw new \LogicException('compileReturnOp: unexpected opcode '.$op->type);
        }
    }
}
