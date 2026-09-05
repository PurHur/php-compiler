<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * ECHO / PRINT opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_ECHO} and
 * {@code TYPE_PRINT}. Wrapped in {@code switch (true)} so original case-level
 * {@code break} semantics are preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_ECHO), Zend/zend_execute.c (ZEND_ECHO /
 * ZEND_PRINT) — move-only Concern extract; no new C ABI.
 */
trait CompileEchoAndPrint
{
    private function compileEchoOrPrintOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock &$basicBlock
    ): void {
        switch (true) {
            case true:
                // Standalone `new DateTime(Immutable)` leaves pendingDateTimePropertyInstant
                // for the next expression — do not stamp unrelated typed property stores (#35802).
                $this->context->pendingDateTimePropertyInstant = null;
                \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($this->context);
                $this->context->intrinsic->builder = $this->context->builder;
                $this->context->callSiteLine = OpCode::TYPE_ECHO === $op->type
                    ? (int) ($op->arg2 ?? 0)
                    : (int) ($op->arg3 ?? 0);
                \PHPCompiler\JIT\Builtin\SapiHeaderGuardJit::emitNoteOutputOrigin(
                    $this->context,
                    $this->context->callSiteLine
                );
                $argOffset = $op->type === OpCode::TYPE_ECHO ? $op->arg1 : $op->arg2;
                $echoOp = $block->getOperand($argOffset);
                // echo/print $this in FLAG_STATIC / {main} / plain function (#31901).
                if (\PHPCompiler\JIT\UnboundThisGuard::emitIfProven($this->context, $this, $block, $echoOp)) {
                    break;
                }
                $scriptGlobalEchoName = (
                    OpCode::TYPE_ECHO === $op->type
                    && null !== $op->echoScriptGlobalName
                    && '' !== $op->echoScriptGlobalName
                    && $block->isMainScript()
                ) ? $op->echoScriptGlobalName : null;
                // CFG string Literals must echo by content (#19504) *before* same-slot
                // Variable rewrite. Try/catch merge blocks reuse SSA slots with catch
                // temps, so rewriting "AFTER" → a Variable prints "caught: "/"getMessage"
                // (#23930, #23641 AFTER regression).
                if (
                    null === $scriptGlobalEchoName
                    && $echoOp instanceof Operand\Literal
                    && \is_string($echoOp->value)
                ) {
                    \PHPCompiler\JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                    \PHPCompiler\JIT\ValueEchoHelper::echoLiteral($this->context, $echoOp->value);
                    break;
                }
                $arg = null;
                // Prefer ASSIGN_REF / by-ref named bindings over {main} script-global echo
                // sidecars — `$o->p =& $v` shares a local box that ensureScriptGlobal misses (#34649).
                $echoNameForByRefEarly = \PHPCompiler\JIT\OperandName::resolve($echoOp);
                if (
                    null !== $echoNameForByRefEarly
                    && '' !== $echoNameForByRefEarly
                ) {
                    $echoNameForByRefEarly = $this->context->resolveRefAliasName($echoNameForByRefEarly);
                    if (isset($this->context->namedVariableBindings[$echoNameForByRefEarly])) {
                        $earlyBound = $this->context->namedVariableBindings[$echoNameForByRefEarly];
                        if (
                            Variable::KIND_VARIABLE === $earlyBound->kind
                            && Variable::TYPE_VALUE === $earlyBound->type
                            && (
                                null !== $earlyBound->valueBoxAliasPtr
                                || $earlyBound->borrowedValueEntry
                                || $earlyBound->assignRefLvalueAlias
                            )
                        ) {
                            $scriptGlobalEchoName = null;
                            $arg = $earlyBound;
                        } elseif (
                            // {main} `$out = $a.$b` keeps a native `__string__*` alloca while
                            // echoScriptGlobalName points at an empty heap box (#36366).
                            Variable::KIND_VARIABLE === $earlyBound->kind
                            && Variable::TYPE_STRING === $earlyBound->type
                        ) {
                            $scriptGlobalEchoName = null;
                            $arg = $earlyBound;
                        } elseif (
                            // {main} int/bool/float counters stay on stack allocas (#36408).
                            Variable::KIND_VARIABLE === $earlyBound->kind
                            && (
                                Variable::TYPE_NATIVE_LONG === $earlyBound->type
                                || Variable::TYPE_NATIVE_BOOL === $earlyBound->type
                                || Variable::TYPE_NATIVE_DOUBLE === $earlyBound->type
                            )
                        ) {
                            $scriptGlobalEchoName = null;
                            $arg = $earlyBound;
                        }
                    }
                }
                if (null === $arg && null !== $scriptGlobalEchoName) {
                    if ($this->shouldDeferScriptGlobalForInlineIncludeBinding($scriptGlobalEchoName, $echoOp, $block)) {
                        $scriptGlobalEchoName = null;
                    } elseif (
                        null !== $argOffset
                        && $this->context->hasVariableOp($echoOp)
                        && $this->jitBlockHasInBlockConcatToSlot($block, (int) $argOffset)
                    ) {
                        // Fused `$out = 'a' . "b"; echo $out` — CONCAT wrote the CV slot (#36366).
                        $arg = $this->context->getVariableFromOp($echoOp);
                        $scriptGlobalEchoName = null;
                    } else {
                        $arg = $this->ensureScriptGlobalForRuntimeRead($echoOp, $scriptGlobalEchoName);
                    }
                }
                if (null === $arg) {
                    if ($echoOp instanceof Operand\Literal && null !== $argOffset) {
                        foreach ($block->scopedOperands() as $scopeOp) {
                            if ($block->slotForOperand($scopeOp) !== $argOffset) {
                                continue;
                            }
                            if (
                                $scopeOp instanceof Operand\Variable
                                || (
                                    $scopeOp instanceof Operand\Temporary
                                    && null !== \PHPCompiler\JIT\OperandName::resolve($scopeOp)
                                )
                            ) {
                                $echoOp = $scopeOp;
                                break;
                            }
                        }
                    }
                    $arg = $this->resolveScriptGlobalForRuntimeRead($echoOp, $block)
                        ?? $this->context->getVariableFromOpInScopes($echoOp);
                }
                if ($this->context->hasVariableOp($echoOp)) {
                    $echoGuardVar = $arg ?? $this->context->getVariableFromOp($echoOp);
                    // Script-global heap boxes are guarded in ensureScriptGlobalForRuntimeRead().
                    if (!$echoGuardVar->functionStaticGlobal) {
                        \PHPCompiler\JIT\UndefinedVariableHelper::guardBeforeRuntimeRead(
                            $this->context,
                            $echoOp,
                            $echoGuardVar
                        );
                    }
                }
                if ($this->context->inlineIncludeDepth > 0) {
                    $echoName = \PHPCompiler\JIT\OperandName::resolve($echoOp);
                    // Refresh before echo of inherited include-bindings after ?? (#866).
                    // OperandName can be null on Temporary wrappers — also refresh when the
                    // live variable still carries includeBinding. Skip post-include locals
                    // like $scriptBase (name not in frame, includeBinding false) (#20507).
                    $echoNeedsRefresh = \PHPCompiler\JIT\IncludeBindingEmitHelper::refreshFrameDeclaresName(
                        $this->context,
                        $echoName
                    );
                    if (!$echoNeedsRefresh && $this->context->hasVariableOp($echoOp)) {
                        $echoVar = $this->context->getVariableFromOp($echoOp);
                        $echoNeedsRefresh = $echoVar->includeBinding
                            && !$this->context->coalesceAssignTargets->contains($echoOp);
                    }
                    if ($echoNeedsRefresh) {
                        \PHPCompiler\JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                        $refreshed = $this->context->getVariableFromOpInScopes($echoOp);
                        if ($refreshed->includeBinding) {
                            $arg = $refreshed;
                        }
                    }
                }
                \PHPCompiler\JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                // Value-boxed assigned locals keep Operand userType (inferred:C) but lose
                // Variable::TYPE_OBJECT — thread the hint into ValueEchoRuntime (#33986).
                $echoClassHint = null;
                $echoUserType = $echoOp->type?->userType ?? null;
                if (\is_string($echoUserType) && '' !== $echoUserType) {
                    $echoUserType = ltrim($echoUserType, '\\');
                    if ('' !== $echoUserType && 'object' !== strtolower($echoUserType)) {
                        $echoClassHint = $echoUserType;
                    }
                }
                // After ZEND_SEND_REF, namedVariableBindings holds the live boxed lvalue.
                // Coalesce/ternary echo-phi maps are keyed by SSA slot and can still name the
                // pre-call constant on the same slot — prefer the by-ref binding (#24162).
                // Also covers `$o->p =& $v` shared boxes under {main} echoScriptGlobalName (#34649).
                $echoNameForByRef = \PHPCompiler\JIT\OperandName::resolve($echoOp);
                if (
                    null !== $echoNameForByRef
                    && '' !== $echoNameForByRef
                    && isset($this->context->namedVariableBindings[$echoNameForByRef])
                ) {
                    $byRefEcho = $this->context->namedVariableBindings[$echoNameForByRef];
                    if (
                        Variable::KIND_VARIABLE === $byRefEcho->kind
                        && Variable::TYPE_VALUE === $byRefEcho->type
                        && (
                            null !== $byRefEcho->valueBoxAliasPtr
                            || $byRefEcho->borrowedValueEntry
                            || $byRefEcho->assignRefLvalueAlias
                        )
                    ) {
                        $arg = $byRefEcho;
                        \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeRead($this->context, $arg);
                        \PHPCompiler\JIT\ValueEchoHelper::echo(
                            $this->context,
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $arg),
                            $echoClassHint
                        );
                        break;
                    }
                }
                if (null !== $this->context->ternaryEchoLiteralConditionSlot) {
                    $ifLiteral = $this->context->ternaryEchoLiteralIf ?? '';
                    $elseLiteral = $this->context->ternaryEchoLiteralElse ?? '';
                    $basicBlock = $this->emitTernaryLiteralEchoMerge(
                        $this->context->ternaryEchoLiteralConditionSlot,
                        $ifLiteral,
                        $elseLiteral,
                        $basicBlock
                    );
                    $this->clearTernaryEchoLiteralMergeState();
                    break;
                }
                $echoSlot = $block->slotForOperand($echoOp);
                if (null === $scriptGlobalEchoName && null !== $echoSlot && isset($this->context->coalesceMergeSlotOperands[$echoSlot])) {
                    $arg = $this->materializeCoalesceMergeSlotArgSend(
                        $block,
                        $this->context->coalesceMergeSlotOperands[$echoSlot]
                    );
                    if (Variable::TYPE_VALUE === $arg->type) {
                        // valuePtrFromVariable — never pointer($arg->value): script globals are
                        // __value__** and a bare bitcast reads the wrong type byte (#24009).
                        \PHPCompiler\JIT\ValueEchoHelper::echo(
                            $this->context,
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $arg),
                            $echoClassHint
                        );
                        break;
                    }
                    // FuncCall ?: arms may leave the coalesce slot as TYPE_STRING (#34814).
                    if (Variable::TYPE_STRING === $arg->type) {
                        \PHPCompiler\JIT\ValueEchoHelper::echoStringVariable($this->context, $arg);
                        break;
                    }
                }
                if (null === $scriptGlobalEchoName && null !== $echoSlot && isset($this->context->ternaryEchoPhiByAliasSlot[$echoSlot])) {
                    // Live boxed / by-ref locals must not be redirected to a stale assign RHS
                    // (e.g. Literal(1) from `$n = 1` before `f($n)`) (#24162, #18052).
                    $skipTernaryPhi = null !== $arg->valueBoxAliasPtr
                        || (
                            Variable::TYPE_VALUE === $arg->type
                            && (
                                Variable::KIND_VARIABLE === $arg->kind
                                || $arg->functionStaticGlobal
                            )
                        );
                    if (!$skipTernaryPhi) {
                        $phiOp = $this->context->ternaryEchoPhiByAliasSlot[$echoSlot];
                        if ($this->context->hasVariableOp($phiOp)) {
                            $arg = $this->materializeCoalesceMergeSlotArgSend($block, $phiOp);
                            if (Variable::TYPE_VALUE === $arg->type) {
                                \PHPCompiler\JIT\ValueEchoHelper::echo(
                                    $this->context,
                                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $arg),
                                    $echoClassHint
                                );
                                break;
                            }
                            if (Variable::TYPE_STRING === $arg->type) {
                                \PHPCompiler\JIT\ValueEchoHelper::echoStringVariable($this->context, $arg);
                                break;
                            }
                        }
                        $echoOp = $phiOp;
                    }
                }
                if (Variable::KIND_VARIABLE === $arg->kind) {
                    $slotType = $this->context->getStringFromType($arg->value->typeOf());
                    // Alloca slots are `__value__*`; bare `__value__` is the struct.
                    // Prefer valueBoxAliasPtr — by-ref send may pass the alias while
                    // `$arg->value` still names a stale pre-promotion alloca (#24162).
                    if (
                        null !== $arg->valueBoxAliasPtr
                        || '__value__' === $slotType
                        || '__value__*' === $slotType
                    ) {
                        \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeRead($this->context, $arg);
                        if (null !== $arg->valueBoxAliasPtr || Variable::TYPE_VALUE === $arg->type) {
                            \PHPCompiler\JIT\ValueEchoHelper::echo(
                                $this->context,
                                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $arg),
                                $echoClassHint
                            );
                        } else {
                            \PHPCompiler\JIT\ValueEchoHelper::echo(
                                $this->context,
                                '__value__*' === $slotType
                                    ? $arg->value
                                    : \PHPCompiler\JIT\JitValueBox::pointer($this->context, $arg->value),
                                $echoClassHint
                            );
                        }
                        break;
                    }
                    if ('__string__' === $slotType && Variable::TYPE_STRING !== $arg->type) {
                        $arg = new Variable(
                            $this->context,
                            Variable::TYPE_STRING,
                            Variable::KIND_VARIABLE,
                            $arg->value
                        );
                    }
                }
                if (null !== $scriptGlobalEchoName) {
                    $sg = $this->context->ensureScriptGlobal($scriptGlobalEchoName);
                    $echoSlot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                    \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                        $this->context,
                        $echoSlot,
                        \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $sg)
                    );
                    \PHPCompiler\JIT\ValueEchoHelper::echo(
                        $this->context,
                        \PHPCompiler\JIT\JitValueBox::pointer($this->context, $echoSlot),
                        $echoClassHint
                    );
                    break;
                }
                switch ($arg->type) {
                    case Variable::TYPE_VALUE:
                        \PHPCompiler\JIT\ValueEchoHelper::echo(
                            $this->context,
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $arg),
                            $echoClassHint
                        );
                        break;
                    case Variable::TYPE_STRING:
                        // Lazy ob_* — bare __phpc_ob_echo_* lookups below (#34695).
                        \PHPCompiler\JIT\Builtin\ObOutputRuntime::ensureLinked($this->context);
                        if ($arg->kind === Variable::KIND_VALUE
                            && 'i8*' === $this->context->getStringFromType($arg->value->typeOf())
                        ) {
                            $byte = $this->context->builder->load($arg->value);
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_char'),
                                $byte
                            );
                            break;
                        }
                        $argValue = \PHPCompiler\JIT\JitStringArg::lowerDominating(
                            $this->context,
                            $arg,
                            'echo string operand'
                        );
                        $offset = $this->context->structFieldIndex($argValue, 'length');
                        $__str__length = $this->context->builder->load(
                            $this->context->builder->structGep($argValue, $offset)
                        );
                        $offset = $this->context->structFieldIndex($argValue, 'value');
                        $__str__value = $this->context->builder->structGep($argValue, $offset);
                        $sizeT = $this->context->getTypeFromString('size_t');
                        $this->context->builder->call(
                            $this->context->lookupFunction('__phpc_ob_echo_substr'),
                            $__str__value,
                            $this->context->builder->zExt($__str__length, $sizeT)
                        );
                        break;
                    case Variable::TYPE_NATIVE_LONG:
                        \PHPCompiler\JIT\ValueEchoHelper::echoNativeLong(
                            $this->context,
                            $this->context->helper->loadValue($arg),
                            $echoOp
                        );
                        break;
                    case Variable::TYPE_NATIVE_DOUBLE:
                        $argValue = $this->context->helper->loadValue($arg);
                        // PG(precision) via ZendDoubleStringRuntime (#21963);
                        // libc %g → zend_gcvt E-form (#32316).
                        $formatted = \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::formatGcvt(
                            $this->context,
                            $argValue
                        );
                        \PHPCompiler\JIT\ValueEchoHelper::echoStringVariable(
                            $this->context,
                            new Variable(
                                $this->context,
                                Variable::TYPE_STRING,
                                Variable::KIND_VALUE,
                                $formatted
                            )
                        );
                        break;
                    case Variable::TYPE_NATIVE_BOOL:
                        \PHPCompiler\JIT\Builtin\ObOutputRuntime::ensureLinked($this->context);
                        $boolVal = $this->context->helper->loadValue($arg);
                        $charPtr = $this->context->getTypeFromString('char*');
                        $trueBlock = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'echo_bool_true');
                        $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'echo_bool_done');
                        $this->context->builder->branchIf($boolVal, $trueBlock, $doneBlock);
                        $this->context->builder->positionAtEnd($trueBlock);
                        $this->context->builder->call(
                            $this->context->lookupFunction('__phpc_ob_echo_cstr'),
                            $this->context->builder->pointerCast(
                                $this->context->constantFromString('1'),
                                $charPtr
                            )
                        );
                        $this->context->builder->branch($doneBlock);
                        $this->context->builder->positionAtEnd($doneBlock);
                        break;

                    case Variable::TYPE_HASHTABLE:
                        \PHPCompiler\JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                        break;
                    case Variable::TYPE_OBJECT:
                        $classHint = $this->opCodeArgSlotType($block, $op, $argOffset)?->userType ?? null;
                        \PHPCompiler\JIT\ValueEchoHelper::echoObjectVariable(
                            $this->context,
                            $arg,
                            $classHint
                        );
                        break;

                    default:
                        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
                            \PHPCompiler\JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                            break;
                        }
                        if (Variable::KIND_VARIABLE === $arg->kind
                            && '__value__' === $this->context->getStringFromType($arg->value->typeOf())
                        ) {
                            \PHPCompiler\JIT\ValueEchoHelper::echo(
                                $this->context,
                                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $arg->value),
                                $echoClassHint
                            );
                            break;
                        }
                        if (Variable::KIND_VALUE === $arg->kind
                            && '__value__*' === $this->context->getStringFromType($arg->value->typeOf())
                        ) {
                            \PHPCompiler\JIT\ValueEchoHelper::echo($this->context, $arg->value, $echoClassHint);
                            break;
                        }
                        if (
                            Variable::TYPE_VALUE === $arg->type
                            && (
                                $arg->functionStaticGlobal
                                || '__value__**' === $this->context->getStringFromType($arg->value->typeOf())
                            )
                        ) {
                            // AOT script-global locals: load __value__** before echo (#24009).
                            \PHPCompiler\JIT\ValueEchoHelper::echo(
                                $this->context,
                                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $arg),
                                $echoClassHint
                            );
                            break;
                        }
                        throw new \LogicException("Echo for type $arg->type not implemented");
                }
                if ($op->type === OpCode::TYPE_PRINT) {
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $this->context->constantFromInteger(1))
                    );
                }
                break;
        }
    }
}
