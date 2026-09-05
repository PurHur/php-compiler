<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;

/**
 * Function-definition and closure opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_FUNCDEF} and
 * {@code TYPE_CLOSURE}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_compile.c (zend_compile_func_decl / zend_compile_closure),
 * Zend/zend_closures.c, Zend/zend_execute.c (ZEND_DECLARE_LAMBDA_FUNCTION) —
 * move-only Concern extract; no new C ABI.
 */
trait CompileFuncdefAndClosure
{
    private function compileFuncdefOrClosureOp(Block $block, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_FUNCDEF:
                $nameOp = $block->getOperand($op->arg1);
                assert($nameOp instanceof Operand\Literal);
                // compileBlock() sets activeFunction for the nested Func; restore the
                // enclosing frame so call-site DnfParamCheck in {main} aborts (#29859),
                // not pend+return like a callee-body throw (#33971 / #33972 regression).
                $savedActiveFunction = $this->context->activeFunction;
                $this->compileBlock($op->block1, $nameOp->value);
                $this->context->activeFunction = $savedActiveFunction;
                break;
            case OpCode::TYPE_CLOSURE:
                if ($this->shouldStubClosureLowering() || null === $op->block1) {
                    // Bootstrap / vendor prelink: closures are not executable yet; represent as null.
                    $nullVar = new Variable(
                        $this->context,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $this->context->getTypeFromString('__value__*')->constNull()
                    );
                    $nullVar->isNullConstant = true;
                    $this->assignOperandValue($block->getOperand($op->arg1), $nullVar->value);
                    break;
                }
                // Mirror VM TYPE_CLOSURE: definition-site class scope on the nested Func so
                // self::class / __CLASS__→self::class lower during AOT (#26459, #25793).
                if (\PHPCompiler\JIT\FiberHelper::blockContainsFiberSuspend($op->block1)) {
                    $this->propagateEnclosingClassOntoClosureFunc($block, $op->block1);
                    $internalName = \PHPCompiler\JIT\ClosureHelper::nextInternalName();
                    $resumeName = strtolower($internalName.'__fiber_resume');
                    \PHPCompiler\JIT\FiberHelper::compileResumeFunction(
                        $this,
                        $resumeName,
                        $op->block1,
                        $internalName
                    );
                    $this->context->scriptFiberResumeName = $resumeName;
                    $closureObj = \PHPCompiler\JIT\FiberHelper::allocateFiberCallbackObject(
                        $this->context,
                        $resumeName
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $closureObj, true);
                    break;
                }
                $internalName = $op->closurePrecompiledInternalName
                    ?? \PHPCompiler\JIT\ClosureHelper::nextInternalName();
                if (null === $op->closurePrecompiledInternalName) {
                    $this->compileClosureBodyBlock($block, $op->block1, $internalName);
                }
                $lcname = strtolower($internalName);
                if (!isset($this->context->functionProxies[$lcname])) {
                    throw new \LogicException("Closure body failed to register JIT proxy: {$internalName}");
                }
                $callProxy = $this->context->functionProxies[$lcname];
                if ([] !== $op->closureCaptures) {
                    $captures = \PHPCompiler\JIT\ClosureHelper::snapshotCapturesForClosure(
                        $this->context,
                        $op->block1,
                        $op->closureCaptures
                    );
                    $callProxy = \PHPCompiler\JIT\ClosureHelper::wrapCallWithCaptures($callProxy, $captures);
                }
                // Bound $this/scope slots must exist before allocate — otherwise
                // storeInstanceProperty writes past the object / fetch reads null
                // and cross-function `$f = $obj->m(); $f()` loses $this (#35456).
                if (null !== $block->func && null !== $block->func->class) {
                    \PHPCompiler\JIT\ClosureBindHelper::ensureClosureBindingProperties($this->context);
                }
                $closureObj = \PHPCompiler\JIT\ClosureHelper::allocateClosureObject(
                    $this->context,
                    $callProxy,
                    $internalName
                );
                $isStaticClosure = null !== $op->block1->func
                    && (($op->block1->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
                if ($isStaticClosure) {
                    $closureObj->closureIsStatic = true;
                    \PHPCompiler\JIT\ClosureBindHelper::storeStaticClosureFlag(
                        $this->context,
                        $this->context->helper->loadValue($closureObj)
                    );
                }
                if (null !== $block->func && null !== $block->func->class) {
                    $scopeName = (string) $block->func->class->value;
                    $scopeLc = strtolower(ltrim($scopeName, '\\'));
                    // Trait method closures: bind ce to the composing class (#26459, #25793).
                    if ($this->context->type->object->isTraitClass($scopeLc)) {
                        $composing = $this->context->scope->traitComposingClassName;
                        if ('' === $composing) {
                            $composing = $this->context->scope->className;
                        }
                        if ('' !== $composing
                            && !$this->context->type->object->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                            if ($this->context->type->object->hasDeclaredClass($composing)) {
                                $scopeName = $this->context->type->object->classNameForId(
                                    $this->context->type->object->lookup($composing)
                                );
                            } else {
                                $scopeName = $composing;
                            }
                        }
                    }
                    $boundScope = new Variable(
                        $this->context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        $this->context->builder->load(
                            $this->context->constantStringFromString($scopeName)
                        )
                    );
                    $boundScope->compileTimeString = $scopeName;

                    // Store the live TYPE_OBJECT $this into Closure's TYPE_OBJECT
                    // __closure_bound_this slot (Object_ seeds TYPE_OBJECT — a VALUE box
                    // store corrupts the pointer slot and cross-function reload sees null).
                    // Create-time ClosureWithBinding still uses a snapshot for in-method
                    // `$g()` (#28612); invoke after return reloads via closureObject /
                    // RuntimeIndirect (#35456).
                    $boundThis = \PHPCompiler\JIT\ClosureHelper::nullCapture($this->context);
                    $boundThisForSlot = $boundThis;
                    if (!$isStaticClosure) {
                        $thisVar = $this->resolveThisVariable($block)
                            ?? $this->context->variableForScopedName('this');
                        if (null !== $thisVar) {
                            $boundThis = \PHPCompiler\JIT\ClosureHelper::snapshotCapture($this->context, $thisVar);
                            if (Variable::TYPE_OBJECT === $thisVar->type) {
                                $boundThisForSlot = $thisVar;
                            } else {
                                $objPtr = $this->context->builder->call(
                                    $this->context->lookupFunction('__value__readObject'),
                                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $thisVar)
                                );
                                $boundThisForSlot = new Variable(
                                    $this->context,
                                    Variable::TYPE_OBJECT,
                                    Variable::KIND_VALUE,
                                    $objPtr
                                );
                                $boundThisForSlot->addref();
                            }
                        }
                    }

                    $obj = $this->context->helper->loadValue($closureObj);
                    \PHPCompiler\JIT\ClosureBindHelper::storeFccBoundThisAndScope(
                        $this->context,
                        $obj,
                        $boundThisForSlot,
                        $boundScope
                    );
                    $closureObj->closureCall = new \PHPCompiler\JIT\Call\ClosureWithBinding(
                        $callProxy,
                        $boundThis,
                        $boundScope
                    );
                    $this->context->lastClosureCallProxy = $closureObj->closureCall;
                }
                // Refresh returned-closure map with the final proxy (captures / binding) (#34868).
                if (null !== $closureObj->closureCall) {
                    $this->recordReturnedClosureProxyForBlock($block, $closureObj->closureCall);
                }
                $this->assignOperand($block->getOperand($op->arg1), $closureObj, true);
                break;
            default:
                throw new \LogicException('compileFuncdefOrClosureOp: unexpected opcode '.$op->type);
        }
    }
}
