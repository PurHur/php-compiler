<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\OpCode;
use PHPCompiler\VM\VmFiberValue;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * MCJIT fiber resume-ip LLVM lowering (#3130, #4019, #10079).
 *
 * Value-box writes delegate type dispatch to {@see VmFiberValue}; thin entry is {@see FiberHelper}.
 * php-src: Zend/zend_fibers.c.
 */
final class FiberHelperLlvm
{
    private static bool $typesRegistered = false;

    public static function blockContainsFiberSuspend(?Block $block): bool
    {
        if (null === $block) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if (self::isFiberSuspendInit($block, $op)) {
                return true;
            }
        }

        return false;
    }

    public static function ensureTypes(Context $context): void
    {
        if (self::$typesRegistered) {
            return;
        }
        self::$typesRegistered = true;
        $struct = $context->context->namedStructType('__fiber_state__');
        $context->registerType('__fiber_state__', $struct);
        $context->registerType('__fiber_state__*', $struct->pointerType(0));
        $struct->setBody(
            false,
            $context->getTypeFromString('size_t'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('int1'),
        );
        $context->structFieldMap['__fiber_state__'] = [
            'resume_ip' => 0,
            'suspend_return' => 1,
            'resume_argument' => 2,
            'fiber_return' => 3,
            'done' => 4,
            'has_pending_throw' => 5,
            'pending_throw' => 6,
            'started' => 7,
            'suspended' => 8,
        ];
    }

    public static function isFiberSuspendInit(Block $block, OpCode $op): bool
    {
        if (OpCode::TYPE_STATICCALL_INIT !== $op->type) {
            return false;
        }
        $classOp = $block->getOperand($op->arg1);
        $nameOp = $block->getOperand($op->arg2);
        if (!$classOp instanceof Operand\Literal || !$nameOp instanceof Operand\Literal) {
            return false;
        }

        return 0 === strcasecmp(ltrim($classOp->value, '\\'), 'Fiber')
            && 0 === strcasecmp($nameOp->value, 'suspend');
    }

    /**
     * @return list<array{op: OpCode, index: int, block: Block}>
     */
    public static function collectSuspendPoints(Block $block): array
    {
        $points = [];
        foreach ($block->opCodes as $i => $op) {
            if (self::isFiberSuspendInit($block, $op)) {
                $points[] = ['op' => $op, 'index' => $i, 'block' => $block];
            } elseif (
                OpCode::TYPE_RETURN === $op->type
                || OpCode::TYPE_RETURN_VOID === $op->type
            ) {
                break;
            }
        }

        return $points;
    }

    private static function opcodeIndex(Block $block, OpCode $target): int
    {
        foreach ($block->opCodes as $i => $op) {
            if ($op === $target) {
                return $i;
            }
        }

        throw new \LogicException('Fiber suspend opcode missing from block');
    }

    /**
     * @param list<array{op: OpCode, index: int}> $points
     */
    private static function resumePrefixStart(Block $block, array $points, int $pointIndex): int
    {
        if (0 === $pointIndex) {
            return 0;
        }

        // Include prior Fiber::suspend STATICCALL_INIT so FUNCCALL_EXEC can load resume_argument (#26801).
        return self::opcodeIndex($block, $points[$pointIndex - 1]['op']);
    }

    public static function compileResumeFunction(
        \PHPCompiler\JIT $jit,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $context = $jit->context;
        self::ensureTypes($context);
        $lc = strtolower($internalName);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }

        $statePtrTy = $context->getTypeFromString('__fiber_state__*');
        $i64 = $context->getTypeFromString('int64');
        $func = $context->module->addFunction(
            self::llvmInternalName($internalName),
            $context->context->functionType($i64, false, $statePtrTy)
        );
        $stateParam = $func->getParam(0);
        $savedBuilder = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->compilingFiberResume = true;
        $context->fiberStateParam = $stateParam;

        $entry = $func->appendBasicBlock('fiber_entry');
        $context->builder->positionAtEnd($entry);
        $points = self::collectSuspendPoints($block);
        $n = count($points);
        $map = $context->structFieldMap['__fiber_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);

        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['done']));
        $resumeIp = $context->builder->load($context->builder->structGep($stateParam, $map['resume_ip']));
        $doneBb = $func->appendBasicBlock('fiber_done');
        $switchInst = $context->builder->branchSwitch($resumeIp, $doneBb, $n);

        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBb = $func->appendBasicBlock('fiber_case_'.$i);
            $switchInst->addCase($sizeT->constInt($i, false), $caseBb);
            $caseBlocks[$i] = $caseBb;
        }

        for ($i = 0; $i < $n; ++$i) {
            $prefixEntry = self::emitPendingThrowGate(
                $jit,
                $func,
                $stateParam,
                $caseBlocks[$i]
            );
            $suspendIdx = $points[$i]['index'];
            $prefixStart = self::resumePrefixStart($block, $points, $i);
            $resumeTail = $prefixEntry;
            if ($prefixStart < $suspendIdx) {
                $savedStorage = $context->scope->blockStorage;
                $context->scope->blockStorage = new \SplObjectStorage();
                $resumeTail = $jit->compileGeneratorResumePrefix($func, $block, $prefixStart, $suspendIdx, $prefixEntry);
                $context->builder->positionAtEnd($resumeTail);
                $context->scope->blockStorage = $savedStorage;
            }
            $context->builder->positionAtEnd($resumeTail);
            self::emitSuspendPoint($jit, $block, $points[$i]['op'], $stateParam, $i + 1);
        }

        $context->builder->positionAtEnd($doneBb);
        // Fiber::throw()/resume after the last suspend lands here (resume_ip == n).
        // Inject pending throw before running the post-suspend tail (#27622).
        $doneEntry = self::emitPendingThrowGate($jit, $func, $stateParam, $doneBb);
        // Include last suspend STATICCALL_INIT so resume_argument is bound to Fiber::suspend() (#26801).
        $tailStart = [] === $points ? 0 : self::opcodeIndex($block, $points[count($points) - 1]['op']);
        $returnIdx = null;
        foreach ($block->opCodes as $i => $op) {
            if ($i < $tailStart) {
                continue;
            }
            if (OpCode::TYPE_RETURN === $op->type || OpCode::TYPE_RETURN_VOID === $op->type) {
                $returnIdx = $i;
                break;
            }
        }
        $context->builder->positionAtEnd($doneEntry);
        if (null !== $returnIdx && $tailStart < $returnIdx) {
            $savedStorage = $context->scope->blockStorage;
            $context->scope->blockStorage = new \SplObjectStorage();
            $exit = $jit->compileGeneratorResumePrefix($func, $block, $tailStart, $returnIdx, $doneEntry);
            $context->builder->positionAtEnd($exit);
            $context->scope->blockStorage = $savedStorage;
            $retOp = $block->opCodes[$returnIdx];
            if (OpCode::TYPE_RETURN === $retOp->type && null !== $retOp->arg1) {
                $retVar = $context->getVariableFromOp($block->getOperand($retOp->arg1));
                self::assignValueField(
                    $context,
                    $context->builder->structGep($stateParam, $map['fiber_return']),
                    $retVar,
                    $block->getOperand($retOp->arg1)
                );
            } else {
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $context->builder->structGep($stateParam, $map['fiber_return']))
                );
            }
        }
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->clearInsertionPosition();
        $context->builder = $savedBuilder;
        $context->compilingFiberResume = false;
        $context->fiberStateParam = null;

        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = 'int64';
        $context->functionProxies[$lc] = new Native($func, $internalName, [$statePtrTy], []);

        return $func;
    }

    private static function emitSuspendPoint(
        \PHPCompiler\JIT $jit,
        Block $block,
        OpCode $suspendInitOp,
        Value $stateParam,
        int $nextResumeIp
    ): void {
        $context = $jit->context;
        $map = $context->structFieldMap['__fiber_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $suspendIdx = self::opcodeIndex($block, $suspendInitOp);
        $suspendReturnField = $context->builder->structGep($stateParam, $map['suspend_return']);
        $resumeArgField = $context->builder->structGep($stateParam, $map['resume_argument']);
        $resultOp = null;
        for ($i = $suspendIdx; $i < count($block->opCodes); ++$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                if (null !== $op->arg1) {
                    $resultOp = $block->getOperand($op->arg1);
                }
                break;
            }
        }
        $hasArg = false;
        for ($i = $suspendIdx + 1; $i < count($block->opCodes); ++$i) {
            if (OpCode::TYPE_ARG_SEND === $block->opCodes[$i]->type) {
                $hasArg = true;
                $argOp = $block->getOperand($block->opCodes[$i]->arg1);
                $argVar = $context->getVariableFromOp($argOp);
                self::assignValueField($context, $suspendReturnField, $argVar, $argOp);
                break;
            }
        }
        if (!$hasArg) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $suspendReturnField)
            );
        }
        if (null !== $resultOp) {
            $resultVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, JitValueBox::alloc($context));
            JitValueBox::copyFromPointer($context, $resultVar->value, $resumeArgField);
            if ($context->hasVariableOp($resultOp)) {
                $dest = $context->getVariableFromOp($resultOp);
                if (Variable::KIND_VALUE === $dest->kind) {
                    JitValueBox::copyFromPointer(
                        $context,
                        $dest->value,
                        JitValueBox::valuePtrFromVariable($context, $resultVar)
                    );
                } else {
                    $jit->assignOperandForced($resultOp, $resultVar);
                }
            }
        }
        $context->builder->store(
            $sizeT->constInt($nextResumeIp, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(1, false));
    }

    public static function allocateFiberCallbackObject(Context $context, string $resumeInternalName): Variable
    {
        $classId = $context->type->object->lookup('Closure');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        self::storeResumeName($context, $obj, $resumeInternalName);
        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->fiberResumeName = $resumeInternalName;

        return $var;
    }

    public static function initFiberState(Context $context): Value
    {
        self::ensureTypes($context);
        $stateTy = $context->getTypeFromString('__fiber_state__');
        $statePtr = $context->memory->malloc($stateTy);
        $map = $context->structFieldMap['__fiber_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_pending_throw']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['started']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['suspended']));
        foreach (['suspend_return', 'resume_argument', 'fiber_return', 'pending_throw'] as $field) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map[$field]))
            );
        }

        return $statePtr;
    }

    public static function loadResumeNameFromObject(Context $context, Variable $obj): string
    {
        if (null !== $obj->fiberResumeName) {
            return $obj->fiberResumeName;
        }
        throw new \LogicException('Fiber callback missing __fiber_resume metadata');
    }

    public static function storeStateOnFiberObject(Context $context, Value $fiberObj, Value $statePtr): void
    {
        $bits = $context->builder->ptrtoint($statePtr, $context->getTypeFromString('int64'));
        $bitsVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $bits);
        $context->type->object->storeInstanceProperty($fiberObj, 'Fiber', FiberHelper::STATE_PROPERTY, $bitsVar);
    }

    public static function loadStateFromFiberObject(Context $context, Variable $fiberVar): Value
    {
        if (null !== $fiberVar->fiberStatePtr) {
            return $fiberVar->fiberStatePtr;
        }
        self::ensureTypes($context);
        $objVal = $context->helper->loadValue($fiberVar);
        $bitsVar = $context->type->object->propertyFetch($objVal, 'Fiber', FiberHelper::STATE_PROPERTY);
        $bits = $context->helper->loadValue($bitsVar);

        return $context->builder->inttoptr($bits, $context->getTypeFromString('__fiber_state__*'));
    }

    public static function storeResumeNameOnFiber(Context $context, Value $fiberObj, string $resumeName): void
    {
        self::storeResumeNameOnClass($context, $fiberObj, 'Fiber', $resumeName);
    }

    private static function storeResumeName(Context $context, Value $obj, string $resumeName): void
    {
        self::storeResumeNameOnClass($context, $obj, 'Closure', $resumeName);
    }

    private static function storeResumeNameOnClass(
        Context $context,
        Value $obj,
        string $className,
        string $resumeName
    ): void {
        $targetStr = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(strtolower($resumeName)))
        );
        $targetStr->addref();
        $context->type->object->storeInstanceProperty(
            $obj,
            $className,
            FiberHelper::TARGET_PROPERTY,
            $targetStr
        );
    }

    public static function assignValueField(Context $context, Value $destField, Variable $src, ?Operand $srcOp = null): void
    {
        $destPtr = JitValueBox::normalizeValuePtr($context, $destField);
        if (VmFiberValue::isValueBoxCopy($src->type)) {
            JitValueBox::copyFromPointer(
                $context,
                $destField,
                JitValueBox::valuePtrFromVariable($context, $src)
            );

            return;
        }
        $writeFn = VmFiberValue::writeFunctionForJitType($src->type);
        if (null !== $writeFn) {
            if ('__value__writeNull' === $writeFn) {
                $context->builder->call($context->lookupFunction($writeFn), $destPtr);
            } else {
                $context->builder->call(
                    $context->lookupFunction($writeFn),
                    $destPtr,
                    $context->helper->loadValue($src)
                );
            }

            return;
        }
        if (null !== $srcOp && $srcOp instanceof Operand\Literal && null !== $srcOp->type) {
            self::assignValueField($context, $destField, Variable::fromLiteral($context, $srcOp), null);

            return;
        }
        throw new \LogicException(VmFiberValue::ERROR_UNSUPPORTED);
    }

    public static function resolveResumeLc(Context $context, Variable $fiberVar): string
    {
        if (null !== $fiberVar->fiberResumeName) {
            return strtolower($fiberVar->fiberResumeName);
        }
        $objVal = $context->helper->loadValue($fiberVar);
        $key = spl_object_id($objVal);
        if (isset($context->fiberResumeByObjectValueId[$key])) {
            return $context->fiberResumeByObjectValueId[$key];
        }
        if (null !== $context->scriptFiberResumeName) {
            return $context->scriptFiberResumeName;
        }
        throw new \LogicException('Fiber missing __fiber_resume metadata in JIT');
    }

    public static function runResumeAndBoxResult(Context $context, string $resumeLc, Value $statePtr): Variable
    {
        $resumeFn = $context->functions[strtolower($resumeLc)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException("Fiber resume function missing: {$resumeLc}");
        }
        $map = $context->structFieldMap['__fiber_state__'];
        $i64 = $context->getTypeFromString('int64');
        $status = $context->builder->call($resumeFn, $statePtr);
        // status 1 = suspended; 0 = done; 2 = uncaught throw (Fiber::throw only) (#27622).
        $suspended = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $status, $i64->constInt(1, false));
        $context->builder->store(
            $context->builder->zext($suspended, $context->getTypeFromString('int1')),
            $context->builder->structGep($statePtr, $map['suspended'])
        );
        $suspendSlot = JitValueBox::alloc($context);
        $terminatedSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $suspendSlot,
            $context->builder->structGep($statePtr, $map['suspend_return'])
        );
        // Zend/zend_fibers.c: start()/resume() return NULL when fiber terminates (#10149).
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $terminatedSlot)
        );
        $resultPtr = $context->builder->select(
            $suspended,
            JitValueBox::pointer($context, $suspendSlot),
            JitValueBox::pointer($context, $terminatedSlot)
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $resultPtr);
    }

    /**
     * Fiber status probes for AOT (VM builtins are unavailable in native binaries) (#26801).
     *
     * @param 'started'|'suspended'|'terminated'|'running' $which
     */
    public static function loadStatusBool(Context $context, Variable $fiberVar, string $which): Variable
    {
        self::ensureTypes($context);
        $map = $context->structFieldMap['__fiber_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $statePtr = $fiberVar->fiberStatePtr ?? self::loadStateFromFiberObject($context, $fiberVar);
        $stateBits = $context->builder->ptrtoint($statePtr, $i64);
        $hasState = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $stateBits,
            $i64->constInt(0, false)
        );
        $started = $context->builder->load($context->builder->structGep($statePtr, $map['started']));
        $suspended = $context->builder->load($context->builder->structGep($statePtr, $map['suspended']));
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $flag = match ($which) {
            'started' => $started,
            'suspended' => $suspended,
            'terminated' => $done,
            'running' => $context->builder->and(
                $started,
                $context->builder->and(
                    $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $suspended, $i1->constInt(0, false)),
                    $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $done, $i1->constInt(0, false))
                )
            ),
            default => throw new \LogicException("Unknown fiber status probe: {$which}"),
        };
        $bool = $context->builder->and(
            $hasState,
            $context->builder->icmp(\PHPLLVM\Builder::INT_NE, $flag, $i1->constInt(0, false))
        );

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $bool
        );
    }

    private static function llvmInternalName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if ('main' === $sanitized || '__init__' === $sanitized || '__shutdown__' === $sanitized) {
            return 'php_user_'.$sanitized;
        }

        return $sanitized;
    }

    private static function emitPendingThrowGate(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        Value $stateParam,
        \PHPLLVM\BasicBlock $caseBlock
    ): \PHPLLVM\BasicBlock {
        $context = $jit->context;
        $map = $context->structFieldMap['__fiber_state__'];
        $i1 = $context->getTypeFromString('int1');
        $normalEntry = $func->appendBasicBlock('fiber_resume_normal');
        $throwEntry = $func->appendBasicBlock('fiber_resume_throw_inject');
        $context->builder->positionAtEnd($caseBlock);
        $hasPending = $context->builder->load(
            $context->builder->structGep($stateParam, $map['has_pending_throw'])
        );
        $context->builder->branchIf(
            $context->builder->icmp(\PHPLLVM\Builder::INT_NE, $hasPending, $i1->constInt(0, false)),
            $throwEntry,
            $normalEntry
        );
        $context->builder->positionAtEnd($throwEntry);
        $pendingField = $context->builder->structGep($stateParam, $map['pending_throw']);
        $excObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::pointer($context, $pendingField)
        );
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        $branchedToDispatch = false;
        if (null !== $handler && null !== $handler->dispatchBb) {
            // Resume-function dispatchBbs are appended to $func during this compile —
            // branch without sameLlvmFunction (wrapper identity can miss; #27518 / #27622).
            if ($context->compilingFiberResume) {
                JitThrow::registerDeclarations($context);
                JitThrow::ensureLinked($context);
                $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $excObj);
                $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_pending_throw']));
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $pendingField)
                );
                $context->builder->branch($handler->dispatchBb);
                $branchedToDispatch = true;
            } else {
                $dispatchParent = $handler->dispatchBb->getParent();
                $insert = $context->builder->getInsertBlock();
                $parent = null !== $insert ? $insert->getParent() : null;
                if (
                    $parent instanceof \PHPLLVM\Value\Function_
                    && $dispatchParent instanceof \PHPLLVM\Value\Function_
                    && TryCatchHelper::sameLlvmFunction($parent, $dispatchParent)
                ) {
                    JitThrow::registerDeclarations($context);
                    JitThrow::ensureLinked($context);
                    $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $excObj);
                    $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_pending_throw']));
                    $context->builder->call(
                        $context->lookupFunction('__value__writeNull'),
                        JitValueBox::pointer($context, $pendingField)
                    );
                    $context->builder->branch($handler->dispatchBb);
                    $branchedToDispatch = true;
                }
            }
        }
        if (!$branchedToDispatch) {
            // No in-fiber catch: status 2 → Fiber::throw() pends in caller IR (#27622).
            // Keep pending_throw object alive for the caller; writeNull here frees it and
            // leaves FiberThrow with a dangling $excObj (empty get_class / segfault).
            $i64 = $context->getTypeFromString('int64');
            $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_pending_throw']));
            $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
            $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['suspended']));
            $context->builder->returnValue($i64->constInt(2, false));
        }
        $context->builder->positionAtEnd($normalEntry);

        return $normalEntry;
    }
}
