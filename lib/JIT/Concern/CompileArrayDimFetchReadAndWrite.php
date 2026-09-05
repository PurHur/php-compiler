<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Array dimension fetch / fetch-write opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_ARRAY_DIM_FETCH} and
 * {@code TYPE_ARRAY_DIM_FETCH_WRITE} so the monolith switch shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c (ZEND_FETCH_DIM_R / ZEND_FETCH_DIM_W / ZEND_FETCH_DIM_RW),
 * Zend/zend_hash.c (zend_hash_index_find / update) — move-only Concern extract; no new
 * C ABI and no opcode/IR shape change.
 */
trait CompileArrayDimFetchReadAndWrite
{
    private function compileArrayDimFetchOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $basicBlock
    ): void {
        // FETCH_DIM_W for by-ref return even if CFG left TYPE_ARRAY_DIM_FETCH
        // (`return $GLOBALS['x']` / `$a[$i]` from function &f — #34733 / re-#34717).
        $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type
            || $this->varFetchDestUsedAsByRefReturn($block, $i, (int) $op->arg1);
        $fetchIs = !$forWrite && $op->arrayDimFetchIs;
        $warnUndefKeyIncDec = $forWrite && (
            $this->varFetchDestUsedAsIncDec($block, $i, (int) $op->arg1)
            || $this->varFetchDestUsedAsCompoundAssign($block, $i, (int) $op->arg1)
            || $this->varFetchDestUsedAsDimRwContainer($block, $i, (int) $op->arg1)
        );
        $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
        // Zend: E_NOTICE + continue on non-object __get temp (#29231, re-#4673).
        if (
            $forWrite
            && null !== $value->magicGetOverloadedClass
            && null !== $value->magicGetOverloadedName
            && Variable::TYPE_OBJECT !== $value->type
        ) {
            \PHPCompiler\JIT\MagicMethodDispatch::emitMagicGetIndirectModifyNotice(
                $this->context,
                $value->magicGetOverloadedClass,
                $value->magicGetOverloadedName
            );
        }
        $resultOp = $block->getOperand($op->arg1);
        $forceBranchMerge = $this->context->coalesceAssignTargets->contains($resultOp);
        // Nested FETCH_DIM_W (`$a[0][1]` / by-ref return): outer must yield the live
        // child HT (#24011), not prepareIndexWrite orphan — CFG often types the
        // intermediate as mixed (#34745 / re-#34740).
        $dimExpectedType = $this->dimFetchExpectedType(
            $block,
            $i,
            (int) $op->arg1,
            $resultOp->type,
            $forWrite
        );
        // ZEND_FETCH_DIM_W: null/false containers auto-vivify (#21992, #22650).
        if ($forWrite && Variable::TYPE_NULL === $value->type) {
            \PHPCompiler\JIT\HashTableHelper::initArray($this->context, $value);
            $this->context->setVariableOp($block->getOperand($op->arg2), $value);
        }
        if ($forWrite) {
            // FETCH_DIM_W defines the CV — quiet later bare reads after undef
            // autovivify (#29146, re-#21992; Zend/zend_execute.c).
            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                $this->context,
                $block->getOperand($op->arg2),
                $value
            );
        }
        if ($forWrite && Variable::TYPE_NATIVE_BOOL === $value->type) {
            // Runtime: false→[] + E_DEPRECATED; true→Error (zend_execute.c / #22650, #22828).
            $boolVal = $this->context->helper->loadValue($value);
            $isTrue = $this->context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $boolVal,
                $boolVal->typeOf()->constInt(0, false)
            );
            $errBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'dim_w_bool_true_err');
            $okBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'dim_w_bool_false_ok');
            $this->context->builder->branchIf($isTrue, $errBb, $okBb);
            $this->context->builder->positionAtEnd($errBb);
            \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
            \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
            \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                $this->context,
                \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
            );
            $this->context->builder->call($this->context->lookupFunction('abort'));
            $this->context->builder->positionAtEnd($okBb);
            $deprecationLine = null !== $op->sourceLocation && $op->sourceLocation->startLine > 0
                ? $op->sourceLocation->startLine
                : 0;
            \PHPCompiler\JIT\DynamicPropertyDeprecationGuard::emitFalseToArray(
                $this->context,
                $block->scriptPath(),
                $deprecationLine
            );
            \PHPCompiler\JIT\HashTableHelper::initArray($this->context, $value);
            $this->context->setVariableOp($block->getOperand($op->arg2), $value);
        }
        if (null === $op->arg3) {
            $bracketLabel = Variable::cannotUseBracketLabel($value->type);
            if (null !== $bracketLabel) {
                \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                    $this->context,
                    \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
                );
                return;
            }
            if (Variable::TYPE_STRING === $value->type) {
                \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                    $this->context,
                    \PHPCompiler\VM\TypeCheck::STRING_APPEND_UNSUPPORTED_MESSAGE
                );
                return;
            }
            // ArrayObject / ArrayIterator `$o[] =` → append into `__spl_ht`.
            // reserveAppendSlot on the object value-box clobbers it (#27286).
            $appendContainerOp = $block->getOperand($op->arg2);
            $appendUserType = $appendContainerOp->type->userType ?? '';
            if (
                \PHPCompiler\VM\ArrayObjectJitHelper::supportsEmptyDimAppend($appendUserType)
                && (
                    Variable::TYPE_OBJECT === $value->type
                    || Variable::TYPE_VALUE === $value->type
                )
            ) {
                $splHt = \PHPCompiler\VM\ArrayObjectJitHelper::backingHashtableForAppend(
                    $this->context,
                    $value
                );
                $this->context->setVariableOp(
                    $resultOp,
                    \PHPCompiler\JIT\HashTableHelper::reserveAppendSlot($this->context, $splHt)
                );
                return;
            }
            $this->context->setVariableOp(
                $resultOp,
                \PHPCompiler\JIT\HashTableHelper::reserveAppendSlot($this->context, $value)
            );
            return;
        }
        $dimOp = $block->getOperand($op->arg3);
        $dim = $this->context->getVariableFromOp($dimOp);
        // Coalesce left fetch: isset already emitted float→int DEP (#29664).
        $emitFloatKeyDeprecation = !$op->arrayDimFetchSkipFloatKeyDeprecation;
        if (
            !$emitFloatKeyDeprecation
            && Variable::TYPE_NATIVE_DOUBLE === $dim->type
        ) {
            $truncated = $this->context->builder->fptosi(
                $this->context->helper->loadValue($dim),
                $this->context->getTypeFromString('int64')
            );
            $dim = new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $truncated
            );
        }
        $containerOp = $block->getOperand($op->arg2);
        $containerUserType = $containerOp->type->userType ?? '';
        // User-script AOT: SimpleXMLElement dim via host tree (#26863, #27438).
        // CFG types child views as "unknown", so ArrayAccess is skipped; fold here.
        // Root from simplexml_load_* never gets magicGetOverloadedClass (only __get /
        // prior dim results do) — also accept php-types userType / typo alias
        // simplemxml_element, same as property-fetch fold (#26863).
        $sxeDimClass = $value->magicGetOverloadedClass ?? null;
        if (
            (null === $sxeDimClass || '' === $sxeDimClass)
            && \is_string($containerUserType)
            && '' !== $containerUserType
        ) {
            $sxeDimClass = $containerUserType;
        }
        $sxeDimClassLc = null !== $sxeDimClass
            ? strtolower(ltrim((string) $sxeDimClass, '\\'))
            : '';
        // FETCH_DIM_W on a host SXE tree: do not hashtable-write a TYPE_VALUE
        // box (SIGSEGV). Host-fold at ASSIGN via tryOffsetSet (#35810).
        if ($forWrite && \PHPCompiler\JIT\UserScriptAotEnv::isActive()) {
            $sxeWrite = $this->context->extensionLowering->tryPrepareDimWrite(
                $this->context,
                $value,
                $dim
            );
            if (null !== $sxeWrite) {
                $this->context->setVariableOp($resultOp, $sxeWrite);

                return;
            }
        }
        if (
            !$forWrite
            && \PHPCompiler\JIT\UserScriptAotEnv::isActive()
            && (
                'simplexmlelement' === $sxeDimClassLc
                || 'simplemxml_element' === $sxeDimClassLc
            )
        ) {
            $sxeDim = $this->context->extensionLowering->tryOffsetGet(
                $this->context,
                $value,
                $dim
            );
            if (null !== $sxeDim) {
                if ($forceBranchMerge) {
                    $this->assignOperandValue($resultOp, $sxeDim, true);
                } else {
                    $this->assignOperandValue($resultOp, $sxeDim);
                }
                $dimVar = $this->context->getVariableFromOp($resultOp);
                $dimVar->magicGetOverloadedClass = 'SimpleXMLElement';
                // Bind host tree + baked name/text onto the dim result Variable
                // so (string)$sxe['attr'] / getName fold without NestedJIT (#27438).
                $this->context->extensionLowering->applyPendingElementAssign(
                    $dimVar
                );
                return;
            }
        }
        // User-script AOT: `$nodes[$i]` from SimpleXMLElement::xpath() (#26911).
        if (
            !$forWrite
            && \PHPCompiler\JIT\UserScriptAotEnv::isActive()
        ) {
            $sxeListDim = $this->context->extensionLowering->tryFoldXpathListDim(
                $this->context,
                $value,
                $dim
            );
            if (null !== $sxeListDim) {
                // Keep the same Variable so SplObjectStorage tree lookup survives
                // assignOperandValue (lastTree fallback would pick the wrong node).
                if ($forceBranchMerge) {
                    $this->assignOperand($resultOp, $sxeListDim, true);
                    $resultDim = $this->context->getVariableFromOp($resultOp);
                    if (null !== $sxeListDim->compileTimeString) {
                        $resultDim->compileTimeString = $sxeListDim->compileTimeString;
                    }
                } else {
                    $this->context->setVariableOp($resultOp, $sxeListDim);
                    $resultDim = $sxeListDim;
                }
                $resultDim->magicGetOverloadedClass = 'SimpleXMLElement';
                return;
            }
        }
        if ($fetchIs) {
            // FETCH_DIM_IS for nested isset()/empty() chains (#21991).
            $bracketLabel = Variable::cannotUseBracketLabel($value->type);
            if (null !== $bracketLabel) {
                $nullBox = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                $nullVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $nullBox
                );
                $this->context->setVariableOp($resultOp, $nullVar);
                return;
            }
            if (
                $value->type === Variable::TYPE_HASHTABLE
                || Variable::TYPE_VALUE === $value->type
                || ($value->type & Variable::IS_NATIVE_ARRAY)
            ) {
                $htVar = Variable::TYPE_HASHTABLE === $value->type
                    ? $value
                    : \PHPCompiler\JIT\HashTableHelper::asDetachedHashtable($this->context, $value);
                $ht = \PHPCompiler\JIT\HashTableHelper::loadHashtablePointer($this->context, $htVar);
                $exists = \PHPCompiler\JIT\HashTableHelper::offsetIsSetDim($this->context, $ht, $dim);
                $hasKey = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'dim_is_has');
                $missKey = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'dim_is_miss');
                $doneIs = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'dim_is_done');
                $this->context->builder->branchIf($exists, $hasKey, $missKey);
                $this->context->builder->positionAtEnd($missKey);
                $nullBox = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                $nullVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $nullBox
                );
                $this->context->setVariableOp($resultOp, $nullVar);
                $this->context->builder->branch($doneIs);
                $this->context->builder->positionAtEnd($hasKey);
                $fetched = $value->dimFetch($dim, $resultOp->type, false, $emitFloatKeyDeprecation);
                $this->assignOperand($resultOp, $fetched);
                $this->context->builder->branch($doneIs);
                $this->context->builder->positionAtEnd($doneIs);
                return;
            }
        }
        if (
            'splobjectstorage' === strtolower($containerUserType)
            && (
                Variable::TYPE_OBJECT === $value->type
                || Variable::TYPE_VALUE === $value->type
            )
            && (
                Variable::TYPE_OBJECT === $dim->type
                || Variable::TYPE_VALUE === $dim->type
            )
        ) {
            // AOT boxes `new SplObjectStorage` as TYPE_VALUE (#26787; peer WeakMap #24681).
            if (Variable::TYPE_OBJECT === $value->type) {
                $ht = $this->context->type->object->splBackingHashtable($value);
                $htVal = $this->context->helper->loadValue($ht);
            } else {
                $objPtr = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readObject'),
                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $value)
                );
                $ht = $this->context->type->object->splBackingHashtable(
                    new Variable(
                        $this->context,
                        Variable::TYPE_OBJECT,
                        Variable::KIND_VALUE,
                        $objPtr
                    )
                );
                $htVal = $this->context->helper->loadValue($ht);
            }
            if (Variable::TYPE_OBJECT === $dim->type) {
                $keyObj = $this->context->helper->loadValue($dim);
            } else {
                $keyObj = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readObject'),
                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $dim)
                );
            }
            if ($forWrite) {
                $fetched = \PHPCompiler\JIT\HashTableHelper::writableObjectKeyValueBox(
                    $this->context,
                    $htVal,
                    $keyObj
                );
                $this->context->setVariableOp($resultOp, $fetched);
            } else {
                $fetched = \PHPCompiler\JIT\HashTableHelper::readObjectKeyToValueBox(
                    $this->context,
                    $htVal,
                    $keyObj
                );
                $this->assignOperand($resultOp, $fetched);
            }
            return;
        }
        if ($value->type === Variable::TYPE_STRING) {
            $str = $this->context->helper->loadValue($value);
            if ($forWrite) {
                $charPtr = \PHPCompiler\JIT\StringOffsetHelper::dimFetch(
                    $this->context,
                    $str,
                    $dim
                );
                $this->context->makeVariableFromValueOp($charPtr, $resultOp);
            } else {
                $str = \PHPCompiler\JIT\StringOffsetHelper::readDimAsString(
                    $this->context,
                    $str,
                    $dim
                );
                $this->context->makeVariableFromValueOp($str, $resultOp);
            }
            return;
        }
        // VALUE box + CFG string: do not ensureHashtablePointer (#32764 / #22646 write).
        // String/object dims are array keys — never string-byte offsets (#32798).
        // Array/string function-static defaults are retyped in DECLARE (#32806 / #32814).
        // Do NOT assume functionStaticGlobal + unknown CFG is a string: script locals
        // also set that flag, and value-boxed arrays (untyped static copy, json_decode)
        // then SEGV in __value__readString (#32830 / follow-up to #32837).
        if (
            $forWrite
            && Variable::TYPE_VALUE === $value->type
            && \PHPCompiler\JIT\ValueBoxDimWrite::containerCfgIsString($containerOp->type ?? null)
            && !$value->valueBoxHashtable
            && Variable::TYPE_STRING !== $dim->type
            && Variable::TYPE_OBJECT !== $dim->type
        ) {
            \PHPCompiler\JIT\ValueBoxDimWrite::fetchStringOffsetWriteLvalue(
                $this->context,
                $value,
                $dim,
                $resultOp
            );
            return;
        }
        if ($value->type === Variable::TYPE_HASHTABLE) {
            // SimpleXMLElement::xpath() node-set: fold `$n[$i]` to compile-time SXE (#26911).
            if (!$forWrite) {
                $xpathDim = $this->context->extensionLowering->tryFoldXpathListDim(
                    $this->context,
                    $value,
                    $dim
                );
                if (null !== $xpathDim) {
                    if ($forceBranchMerge) {
                        $this->assignOperand($resultOp, $xpathDim, true);
                    } else {
                        $this->assignOperand($resultOp, $xpathDim);
                    }
                    return;
                }
            }
            $fetched = $value->dimFetch($dim, $dimExpectedType, $forWrite, $emitFloatKeyDeprecation, $warnUndefKeyIncDec);
            if ($forWrite) {
                $this->context->setVariableOp($resultOp, $fetched);
            } else {
                $this->bindDimFetchReadResult($resultOp, $fetched, $forceBranchMerge);
            }
            return;
        }
        if (Variable::TYPE_VALUE === $value->type) {
            if (!$forWrite) {
                $xpathDim = $this->context->extensionLowering->tryFoldXpathListDim(
                    $this->context,
                    $value,
                    $dim
                );
                if (null !== $xpathDim) {
                    if ($forceBranchMerge) {
                        $this->assignOperand($resultOp, $xpathDim, true);
                    } else {
                        $this->assignOperand($resultOp, $xpathDim);
                    }
                    return;
                }
            }
            // Value-boxed ArrayAccess after unserialize() often lacks CFG TYPE_OBJECT
            // (#33636). Never broaden under NestedJitCompileScope — NestedJIT string
            // params are TYPE_VALUE and `$s[$i]` must stay string-dim.
            $cfgIsArray = null !== $containerOp->type
                && \PHPTypes\Type::TYPE_ARRAY === $containerOp->type->type;
            $cfgIsString = null !== $containerOp->type
                && \PHPTypes\Type::TYPE_STRING === $containerOp->type->type;
            if (
                null !== $op->arg3
                && !$value->valueBoxHashtable
                && !\PHPCompiler\JIT\NestedJitCompileScope::isActive()
                && !$cfgIsArray
                && !$cfgIsString
            ) {
                $arrayAccess = \PHPCompiler\JIT\ArrayAccessHelper::tryCompileDimFetch(
                    $this->context,
                    $value,
                    $dim,
                    $containerOp,
                    $forWrite
                );
                if (null !== $arrayAccess) {
                    if ($forWrite) {
                        $this->context->setVariableOp($resultOp, $arrayAccess);
                    } elseif ($forceBranchMerge) {
                        $this->assignOperand($resultOp, $arrayAccess, true);
                    } else {
                        $this->assignOperand($resultOp, $arrayAccess);
                    }
                    return;
                }
            }
            $fetched = $value->dimFetch($dim, $dimExpectedType, $forWrite, $emitFloatKeyDeprecation, $warnUndefKeyIncDec);
            if ($forWrite) {
                $this->context->setVariableOp($resultOp, $fetched);
            } else {
                $this->bindDimFetchReadResult($resultOp, $fetched, $forceBranchMerge);
            }
            return;
        }
        $bracketLabel = Variable::cannotUseBracketLabel($value->type);
        if (null !== $bracketLabel && !$this->context->listUnpackSkipAssignPath) {
            if (!$forWrite) {
                \PHPCompiler\JIT\ScalarDimFetchHelper::lowerScalarDimRead(
                    $this->context,
                    $resultOp,
                    $value
                );
                return;
            }
            \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
            \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
            \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                $this->context,
                \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
            );
            return;
        }
        if (
            $this->context->listUnpackSkipAssignPath
            && (
                Variable::TYPE_NULL === $value->type
                || Variable::TYPE_NATIVE_BOOL === $value->type
                || Variable::TYPE_NATIVE_LONG === $value->type
                || Variable::TYPE_NATIVE_DOUBLE === $value->type
            )
        ) {
            // Guarded list destruct compiles dim fetches on non-array RHS (#4325, #4308); unreachable at run time.
            $boxed = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                \PHPCompiler\JIT\JitValueBox::alloc($this->context)
            );
            $fetched = $boxed->dimFetch($dim, $resultOp->type, $forWrite, true, $warnUndefKeyIncDec);
            if ($forWrite) {
                $this->context->setVariableOp($resultOp, $fetched);
            } elseif ($forceBranchMerge) {
                $this->assignOperand($resultOp, $fetched, true);
            } else {
                $this->assignOperand($resultOp, $fetched);
            }
            return;
        }
        if (Variable::TYPE_OBJECT === $value->type && null !== $op->arg3) {
            $arrayAccess = \PHPCompiler\JIT\ArrayAccessHelper::tryCompileDimFetch(
                $this->context,
                $value,
                $dim,
                $containerOp,
                $forWrite
            );
            if (null !== $arrayAccess) {
                if ($forWrite) {
                    $this->context->setVariableOp($resultOp, $arrayAccess);
                } elseif ($forceBranchMerge) {
                    $this->assignOperand($resultOp, $arrayAccess, true);
                } else {
                    $this->assignOperand($resultOp, $arrayAccess);
                }
                return;
            }
            if (\PHPCompiler\JIT\ArrayAccessHelper::isKnownNonArrayAccessObject(
                $this->context,
                $value,
                $containerOp
            )) {
                \PHPCompiler\JIT\ArrayAccessHelper::emitIllegalOffset($this->context);
                return;
            }
        }
        if ($value->type & Variable::IS_NATIVE_ARRAY && $this->context->analyzer->needsBoundsCheck($value, $dimOp)) {
            $this->context->builder->call(
                $this->context->lookupFunction('__nativearray__boundscheck'),
                $dim->value,
                $this->context->constantFromInteger($value->nextFreeElement)
            );
        }
        $fetched = $value->dimFetch($dim, $dimExpectedType, $forWrite, true, $warnUndefKeyIncDec);
        if ($forceBranchMerge && !$forWrite) {
            $this->assignOperand($resultOp, $fetched, true);
        } else {
            $this->assignOperand($resultOp, $fetched);
        }
    }
}
