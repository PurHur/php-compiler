<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPLLVM;
use PHPCompiler\Block;
use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Variable;

/**
 * Call-result operand assign and scalar return type check (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code emitJitScalarReturnTypeCheck}
 * through {@code assignCallResultOperand} (~580 lines) so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c call result binding / return value handling and
 * Zend/zend_execute_API.c scalar return TypeError paths — move-only Concern
 * extract; no new C ABI and no opcode/IR shape change.
 */
trait CallResultOperandAssign
{
    /**
     * Scalar `: string`/`: int`/… return enforce under strict_types, weak coerce otherwise (#26427).
     *
     * @return bool false when TypeError was emitted (skip ret)
     */
    private function emitJitScalarReturnTypeCheck(Block $block, Variable &$return): bool
    {
        return JIT\ScalarReturnCheck::enforce($this->context, $block, $return);
    }





    /**
     * CFG typed a call result as object (or object|null / object|false) while the LLVM
     * ABI may still return a boxed __value__* (#34019, #34024).
     *
     * @param-out string $className
     */
    private function callResultCfgWantsObject(Operand $result, ?string &$className = null): bool
    {
        $className = 'object';
        if ($this->context->hasVariableOp($result)) {
            $prior = $this->context->getVariableFromOp($result);
            if (Variable::TYPE_OBJECT === $prior->type) {
                $tagged = $prior->classUserType ?? null;
                if (is_string($tagged) && '' !== $tagged) {
                    $className = $tagged;
                } elseif (null !== $result->type && is_string($result->type->userType ?? null) && '' !== $result->type->userType) {
                    $className = $result->type->userType;
                }

                return true;
            }
        }
        $type = $result->type;
        if (null === $type) {
            return false;
        }
        if (Type::TYPE_OBJECT === $type->type) {
            if (is_string($type->userType ?? null) && '' !== $type->userType) {
                $className = $type->userType;
            }

            return true;
        }
        if (Type::TYPE_UNION !== $type->type || [] === ($type->subTypes ?? [])) {
            return false;
        }
        foreach ($type->subTypes as $sub) {
            if (Type::TYPE_OBJECT !== $sub->type) {
                continue;
            }
            if (is_string($sub->userType ?? null) && '' !== $sub->userType) {
                $className = $sub->userType;
            }

            return true;
        }

        return false;
    }

    /**
     * Pin call SSA results in the open insert block after callee CFG splits (#18052).
     *
     * Bool-return instance methods (e.g. DOMDocument::loadHTML) branch to fresh
     * continuations; boxed __value__* results from the next call can be unreachable
     * for assignOperandValue unless copied on-stack in the current block.
     *
     * Runtime-bridge bool builtins (stream_supports_lock, file_exists) return i1 after
     * NestedJitCompileScope helper linking; pin those too so ?: echo / JUMPIF still see
     * a dominated value (#19459).
     */
    private function materializeCallResultReachable(PHPLLVM\Value $llvmResult): PHPLLVM\Value
    {
        $ty = $this->context->getStringFromType($llvmResult->typeOf());
        if ('__object__*' === $ty) {
            JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_result_obj_reach_cont');
            $objSlot = JIT\BasicBlockHelper::entryAlloca($this->context, $this->context->getTypeFromString('__object__*'));
            $this->context->builder->store($llvmResult, $objSlot);

            return $this->context->builder->load($objSlot);
        }
        if ('int1' === $ty || 'bool' === $ty) {
            JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_result_i1_reach_cont');
            $i1Slot = JIT\BasicBlockHelper::entryAlloca(
                $this->context,
                $this->context->getTypeFromString('int1')
            );
            $this->context->builder->store($llvmResult, $i1Slot);

            return $this->context->builder->load($i1Slot);
        }
        if ('__value__*' !== $ty) {
            return $llvmResult;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_result_reach_cont');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $this->context->getTypeFromString('__value__*'));
        $this->context->builder->store(
            JIT\JitValueBox::normalizeValuePtr($this->context, $llvmResult),
            $slot
        );

        return $this->context->builder->load($slot);
    }

    /**
     * True when the current call returns a freshly allocated `__string__*` the caller owns
     * (str_repeat / NestedJIT StrRepeat helper / user `: string` returns). Borrowed
     * `__string__*` results must stay KIND_VALUE so freeDeadVariables is a no-op (#36388).
     */
    private function callResultOwnsFreshString(): bool
    {
        $toCall = $this->context->scope->toCall;
        if ($toCall instanceof CoreFunc\Internal) {
            return $this->isOwningStringInternalName($toCall->getName());
        }
        if ($toCall instanceof JIT\Call\Native) {
            $name = strtolower($toCall->name);
            if ($this->isOwningStringInternalName($name)) {
                return true;
            }
            if (str_contains($name, 'strrepeat')) {
                return true;
            }
            // User / NestedJIT PHP with declared string return — ZEND_RETURN transfers ownership.
            $ret = $this->context->functionReturnType[$name] ?? null;
            if ('__string__*' !== $ret) {
                return false;
            }
            // Builtin/reflection proxies also advertise __string__*; only treat names that
            // were compiled as user funcs (present in functionReturnType from analyzeFunc).
            // Heuristic: exclude dotted LLVM mangles and Reflection* / known runtime helpers.
            if (str_contains($name, 'reflection') || str_starts_with($name, '__')) {
                return false;
            }
            if (str_contains($name, '.') && !str_contains($name, '\\')) {
                return false;
            }

            return true;
        }

        return false;
    }

    private function isOwningStringInternalName(string $name): bool
    {
        static $owning = [
            'str_repeat' => true,
            'str_pad' => true,
        ];

        return isset($owning[strtolower($name)]);
    }

    private function assignCallResultOperand(Operand $result, PHPLLVM\Value $llvmResult, bool $returnsByRef): void
    {
        if ('void' === $this->context->getStringFromType($llvmResult->typeOf())) {
            return;
        }
        if (!$returnsByRef) {
            // Void JIT __construct proxies return null __value__*; never materialize that onto
            // the EXEC_RETURN operand — it shares the `new` temp (#23641). When assignOperand
            // boxed the temp to TYPE_VALUE, the old TYPE_OBJECT-only guard missed it and typed
            // property stores kept an empty object shell (#35752).
            if ($this->isVoidJitConstructCallThatDiscardsExecReturn($this->context->scope->toCall)) {
                if ($this->context->hasVariableOp($result)) {
                    $prior = $this->context->getVariableFromOp($result);
                    if (
                        Variable::TYPE_OBJECT === $prior->type
                        || Variable::TYPE_VALUE === $prior->type
                    ) {
                        $this->markNewObjectConstructedAfterCall(
                            $this->context->scope->toCall,
                            $this->context->scope->args
                        );
                        if ($this->context->scope->toCall instanceof JIT\Call\BcMathNumberConstruct) {
                            $thisArg = $this->context->scope->args[0] ?? null;
                            $ct = ($thisArg instanceof Variable)
                                ? $thisArg->compileTimeBcmathNumber
                                : null;
                            if (null !== $ct) {
                                $prior->compileTimeBcmathNumber = $ct;
                                $name = JIT\OperandName::resolve($result);
                                if (null !== $name && '' !== $name) {
                                    $resolved = $this->context->resolveRefAliasName($name);
                                    if (isset($this->context->namedVariableBindings[$resolved])) {
                                        $this->context->namedVariableBindings[$resolved]
                                            ->compileTimeBcmathNumber = $ct;
                                    }
                                    $this->context->bindVariableByName($resolved, $prior);
                                    $prior->compileTimeBcmathNumber = $ct;
                                }
                            }
                        }

                        return;
                    }
                }

                return;
            }
            // FUNCCALL_EXEC_RETURN must materialize even when php-cfg dropped result usages
            // (nested f(g()) arg temps — strlen(trim($s)), #8561).
            $llvmTy = $this->context->getStringFromType($llvmResult->typeOf());
            if (
                $this->context->hasVariableOp($result)
                && ('__value__*' === $llvmTy || '__value__' === $llvmTy)
            ) {
                $prior = $this->context->getVariableFromOp($result);
                if (Variable::TYPE_OBJECT === $prior->type) {
                    // Legacy path: void __construct on an unboxed TYPE_OBJECT `new` temp.
                    if ($this->isVoidJitConstructCall($this->context->scope->toCall)) {
                        if (
                            $this->context->scope->toCall instanceof JIT\Call\DateTimeConstruct
                            || $this->context->scope->toCall instanceof JIT\Call\DateTimeImmutableConstruct
                        ) {
                            // JitDateTimeConstruct returns an initialized __value__* box (#35752).
                            // Drop the empty New_ shell and assign the box below (#35802).
                        } else {
                            $this->markNewObjectConstructedAfterCall(
                                $this->context->scope->toCall,
                                $this->context->scope->args
                            );
                            if ($this->context->scope->toCall instanceof JIT\Call\BcMathNumberConstruct) {
                                $thisArg = $this->context->scope->args[0] ?? null;
                                $ct = ($thisArg instanceof Variable)
                                    ? $thisArg->compileTimeBcmathNumber
                                    : null;
                                if (null !== $ct) {
                                    $prior->compileTimeBcmathNumber = $ct;
                                    $name = JIT\OperandName::resolve($result);
                                    if (null !== $name && '' !== $name) {
                                        $resolved = $this->context->resolveRefAliasName($name);
                                        if (isset($this->context->namedVariableBindings[$resolved])) {
                                            $this->context->namedVariableBindings[$resolved]
                                                ->compileTimeBcmathNumber = $ct;
                                        }
                                        $this->context->bindVariableByName($resolved, $prior);
                                        $prior->compileTimeBcmathNumber = $ct;
                                    }
                                }
                            }

                            return;
                        }
                    }
                    // Inline f(); g() must not inherit object-typed operand slots (#18052).
                    $prior->free();
                    unset($this->context->scope->variables[$result]);
                }
            }
            $llvmResult = $this->materializeCallResultReachable($llvmResult);
            JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_assign_cont');
            $llvmTy = $this->context->getStringFromType($llvmResult->typeOf());
            if ('int1' === $llvmTy || 'bool' === $llvmTy) {
                // Keep runtime-bridge i1 results in an entry alloca (KIND_VARIABLE) so
                // JUMPIF / ?: literal-echo redirect can reload after CFG splits (#19459).
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $i1Slot = JIT\BasicBlockHelper::entryAlloca(
                    $this->context,
                    $this->context->getTypeFromString('int1')
                );
                $this->context->builder->store($llvmResult, $i1Slot);
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_BOOL,
                        Variable::KIND_VARIABLE,
                        $i1Slot
                    )
                );

                return;
            }
            // Owning `__string__*` from known allocators / typed :string returns only.
            // Promoting every `__string__*` call (borrowed getters, shared returns) made
            // freeDeadVariables delref live strings — MiniWebApp SIGSEGV (#36388).
            // php-src: Zend/zend_execute.c ZEND_ASSIGN of IS_STRING return values.
            if ('__string__*' === $llvmTy && $this->callResultOwnsFreshString()) {
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $strSlot = JIT\BasicBlockHelper::entryAlloca(
                    $this->context,
                    $this->context->getTypeFromString('__string__*')
                );
                $this->context->builder->store($llvmResult, $strSlot);
                $strVar = new Variable(
                    $this->context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VARIABLE,
                    $strSlot
                );
                // Unnamed FUNCCALL result temps must still delref on freeDeadVariables /
                // ASSIGN move — mark ephemeral so Variable::free() always delrefs (#36388).
                $strVar->ephemeralStringTemp = true;
                $this->context->setVariableOp($result, $strVar);
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $strVar);
                }

                return;
            }
            if ($this->context->scope->toCall instanceof JIT\Call\NestedClosureInvoke) {
                $llvmTy = $this->context->getStringFromType($llvmResult->typeOf());
                if ('__value__*' === $llvmTy || '__value__' === $llvmTy || JIT\JitNestedHelperCoerce::isValueBox($this->context, $llvmResult)) {
                    $ptr = JIT\JitNestedHelperCoerce::valueBoxPtrFromHelperResult($this->context, $llvmResult);
                    if ($this->context->hasVariableOp($result)) {
                        $this->context->getVariableFromOp($result)->free();
                    }
                    $slot = JIT\JitValueBox::alloc($this->context);
                    JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                    $this->context->setVariableOp(
                        $result,
                        new Variable(
                            $this->context,
                            Variable::TYPE_VALUE,
                            Variable::KIND_VARIABLE,
                            $slot
                        )
                    );

                    return;
                }
                $this->assignOperandValue($result, $llvmResult, true);

                return;
            }
            // HashTable::iterate() returns the receiver HT for IteratorHelper foreach.
            // CFG often types the Traversable temp as PHPCompiler\VM\Variable (element
            // type leak); keep TYPE_HASHTABLE so ObjectPropertyForeach does not win (#27226).
            if (
                $this->context->scope->toCall instanceof JIT\Call\HashTableIterate
                && '__hashtable__*' === $llvmTy
            ) {
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_HASHTABLE,
                        Variable::KIND_VALUE,
                        $llvmResult
                    )
                );

                return;
            }
            // WeakReference::get() returns an owning __value__ box. Promote to an entry
            // alloca KIND_VARIABLE so freeDeadVariables at ternary/branch edges can
            // valueDelref (KIND_VALUE free is a no-op and would keep the referent) (#27118).
            if ($this->context->scope->toCall instanceof JIT\Call\WeakReferenceGet) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                // Drop the call-local owning box; the entry alloca holds the strong ref.
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    $ptr
                );
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $this->context->setVariableOp($result, $resultVar);
                $this->context->pendingWeakReferenceGetResult = $result;

                return;
            }
            if ($this->context->scope->toCall instanceof JIT\Call\WeakReferenceCreate) {
                $this->assignOperandValue($result, $llvmResult, true);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->classUserType = 'WeakReference';
                }

                return;
            }
            // XMLReader::XML()/fromString() — CFG types XML() as bool (InternalArgInfo) but the
            // static factory returns a __value__ object box. Force VALUE storage + classUserType
            // so ASSIGN/$reader->nodeType do not take the non-object property path (#28670).
            // Instance XML() returns i1 bool after resetting $this — skip (#35106).
            if (
                (
                    $this->context->scope->toCall instanceof JIT\Call\XmlReaderXML
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderFromString
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderFromUri
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderFromStream
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderOpen
                )
                && !(
                    (
                        $this->context->scope->toCall instanceof JIT\Call\XmlReaderXML
                        || $this->context->scope->toCall instanceof JIT\Call\XmlReaderOpen
                    )
                    && !$this->context->extensionLowering->xmlReaderFactoryIsObject()
                )
            ) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = 'XMLReader';
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], 'XMLReader');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }

                return;
            }
            if (
                $this->context->scope->toCall instanceof JIT\Call\XmlWriterToMemory
                || $this->context->scope->toCall instanceof JIT\Call\XmlWriterToUri
                || $this->context->scope->toCall instanceof JIT\Call\XmlWriterToStream
            ) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = 'XMLWriter';
                $this->context->extensionLowering->bindXmlWriterResult($resultVar);
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], 'XMLWriter');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }

                return;
            }
            // DOMElement::removeAttributeNode() — InternalArgInfo still says bool (PHP 5 era)
            // until php-types-dom-removeattributenode-return.patch applies. Force VALUE +
            // DOMAttr so `$removed->name` is not the non-object property path (#32707).
            if ($this->context->scope->toCall instanceof JIT\Call\DomElementRemoveAttributeNode) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = 'DOMAttr';
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], 'DOMAttr');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }

                return;
            }
            // Call ABI returns __value__* / __value__ while CFG typed the result as an
            // object (or object|null / object|false union). Inline `$call()?->prop` then
            // kept TYPE_OBJECT storage, so nullsafe skipped the value-box short-circuit and
            // property-fetch GEPed the box (empty / SIGSEGV). Force TYPE_VALUE + classUserType
            // for all such calls — not a per-Call whitelist (#34019 getElementById; #34024
            // cloneNode / createElement / importNode / appendChild; peer #32707).
            $objectClassName = null;
            if (
                ('__value__*' === $llvmTy || '__value__' === $llvmTy)
                && $this->callResultCfgWantsObject($result, $objectClassName)
            ) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = $objectClassName ?? 'object';
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], $objectClassName ?? 'object');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }
                JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_value_box_object_post_assign');

                return;
            }
            if (
                $this->context->hasVariableOp($result)
                && ('__value__*' === $llvmTy || '__value__' === $llvmTy)
                && $this->context->scope->toCall instanceof CoreFunc\Internal
            ) {
                $prior = $this->context->getVariableFromOp($result);
                if (Variable::TYPE_VALUE !== $prior->type) {
                    $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                    $prior->free();
                    $slot = JIT\JitValueBox::alloc($this->context);
                    JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                    $this->context->setVariableOp(
                        $result,
                        new Variable(
                            $this->context,
                            Variable::TYPE_VALUE,
                            Variable::KIND_VARIABLE,
                            $slot
                        )
                    );

                    return;
                }
            }
            $this->assignOperandValue($result, $llvmResult, true);

            return;
        }
        // By-ref FUNCCALL_EXEC_RETURN must materialize even when php-cfg dropped result
        // usages — otherwise ARG_SEND / var_dump(f()) dumps a fresh null box while the
        // call's __value__* is dead (#34717; peer by-value path #8561).
        $ptr = '__value__*' === $this->context->getStringFromType($llvmResult->typeOf())
            ? JIT\JitValueBox::normalizeValuePtr($this->context, $llvmResult)
            : JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
        if ($this->context->hasVariableOp($result)) {
            $this->context->getVariableFromOp($result)->free();
        }
        $refVar = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $ptr
        );
        $refVar->valueBoxAliasPtr = $ptr;
        $refVar->assignRefLvalueAlias = true;
        $refVar->addref();
        $this->context->setVariableOp($result, $refVar);
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            $this->context->bindVariableByName($resolved, $refVar);
        }
    }
}
