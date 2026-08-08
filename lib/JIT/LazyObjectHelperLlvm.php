<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Call;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmLazyObject;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * MCJIT lazy object init LLVM lowering (#4940, #5318, #10267).
 *
 * Thin entry: {@see LazyObjectHelper}; header field names from {@see VmLazyObject}.
 * php-src: Zend/zend_lazy_objects.c
 */
final class LazyObjectHelperLlvm
{
    public static function registerLazyObject(
        Context $context,
        Value $obj,
        int $initIndex,
        bool $ghost
    ): void {
        $map = $context->structFieldMap['__object__'];
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(1, false),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_PENDING])
        );
        $context->builder->store(
            $i8->constInt($ghost ? 1 : 0, false),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_GHOST])
        );
        $context->builder->store(
            $context->constantFromInteger($initIndex, 'int32'),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_INIT_INDEX])
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_CONSTRUCTED])
        );
    }

    /**
     * Mark lazy only when the runtime class has real (non-pad) instance properties (#21570).
     *
     * MCJIT empty-class pad {@see \PHPCompiler\JitMcjitEmbed::CLASS_PAD_PROPERTY} must not
     * count — Zend treats zero declared properties as immediately non-lazy.
     *
     * @see Zend/zend_lazy_objects.c zend_object_make_lazy
     */
    public static function registerLazyObjectForRuntimeClass(
        Context $context,
        Value $obj,
        int $initIndex,
        bool $ghost,
        Value $classIdVal
    ): void {
        $fn = BasicBlockHelper::parentFunction($context);
        $done = $fn->appendBasicBlock('lazy_reg_done');
        $check = $context->builder->getInsertBlock();
        $objectType = $context->type->object;
        $pad = \PHPCompiler\JitMcjitEmbed::CLASS_PAD_PROPERTY;
        foreach (array_keys($objectType->allClassNamesById()) as $id) {
            $id = (int) $id;
            $eligible = 0;
            foreach ($objectType->instancePropertySets($id) as $propset) {
                if (($propset[1] ?? '') !== $pad) {
                    ++$eligible;
                }
            }
            if (0 === $eligible) {
                continue;
            }
            $caseBlock = $fn->appendBasicBlock('lazy_reg_case_'.$id);
            $next = $fn->appendBasicBlock('lazy_reg_try_'.$id);
            $context->builder->positionAtEnd($check);
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classIdVal, $expected);
            $context->builder->branchIf($isId, $caseBlock, $next);
            $context->builder->positionAtEnd($caseBlock);
            self::registerLazyObject($context, $obj, $initIndex, $ghost);
            $context->builder->branch($done);
            $check = $next;
        }
        $context->builder->positionAtEnd($check);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    public static function emitEnsureInitialized(Context $context, Value $obj): void
    {
        if ([] === $context->lazyInitProxies) {
            return;
        }

        $map = $context->structFieldMap['__object__'];
        // Header flags are i8 (#27302) — icmp must not use i32 0 (module verify fails under AOT).
        $i8 = $context->getTypeFromString('int8');
        $pending = $context->builder->load(
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_PENDING])
        );
        $isPending = $context->builder->icmp(
            Builder::INT_NE,
            $pending,
            $i8->constInt(0, false)
        );

        $entry = $context->builder->getInsertBlock();
        $fn = BasicBlockHelper::parentFunction($context);
        $skip = $fn->appendBasicBlock('lazy_skip_init');
        $init = $fn->appendBasicBlock('lazy_do_init');
        $merge = $fn->appendBasicBlock('lazy_init_merge');

        $context->builder->branchIf($isPending, $init, $skip);
        $context->builder->positionAtEnd($skip);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($init);
        self::emitInitBody($context, $obj);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    private static function emitInitBody(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $classIdVal = $context->builder->load(
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_CLASS_ID])
        );

        $i8 = $context->getTypeFromString('int8');
        $ghost = $context->builder->load(
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_GHOST])
        );
        $initIndex = $context->builder->load(
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_INIT_INDEX])
        );

        $fn = BasicBlockHelper::parentFunction($context);
        $done = $fn->appendBasicBlock('lazy_init_done');
        $n = \count($context->lazyInitProxies);
        $checkBlock = $context->builder->getInsertBlock();

        foreach ($context->lazyInitProxies as $idx => $proxy) {
            $matchBlock = $fn->appendBasicBlock('lazy_init_proxy_'.$idx);
            $nextBlock = ($idx < $n - 1)
                ? $fn->appendBasicBlock('lazy_init_check_'.($idx + 1))
                : $fn->appendBasicBlock('lazy_init_unknown');

            $context->builder->positionAtEnd($checkBlock);
            $expected = $context->constantFromInteger($idx, 'int32');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $initIndex, $expected);
            $context->builder->branchIf($isMatch, $matchBlock, $nextBlock);

            $context->builder->positionAtEnd($matchBlock);
            $isGhost = $context->builder->icmp(
                Builder::INT_NE,
                $ghost,
                $i8->constInt(0, false)
            );
            $ghostBlock = $fn->appendBasicBlock('lazy_init_ghost_'.$idx);
            $proxyBlock = $fn->appendBasicBlock('lazy_init_proxy_body_'.$idx);
            $context->builder->branchIf($isGhost, $ghostBlock, $proxyBlock);

            $context->builder->positionAtEnd($ghostBlock);
            $objectType->resetInstancePropertySlots($obj, $classIdVal);
            $objectType->applyLazyGhostPropertyDefaults($obj, $classIdVal);
            // Detach before initializer so nested access cannot re-enter (#27302 / zend_lazy_objects.c).
            self::emitDetachLazyFlags($context, $obj);
            $thisVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            $thisVar->addref();
            $ghostResult = $proxy->call($context, $thisVar);
            // Zend/zend_lazy_objects.c — Z_TYPE(retval) != IS_NULL → TypeError (#29169).
            self::emitGhostInitializerMustReturnNullCheck($context, $ghostResult, $idx);
            $ghostOkInsert = BasicBlockHelper::tryGetInsertBlock($context);
            if (null !== $ghostOkInsert && null === $ghostOkInsert->getTerminator()) {
                $context->builder->branch($done);
            }

            $context->builder->positionAtEnd($proxyBlock);
            self::emitDetachLazyFlags($context, $obj);
            $proxyThis = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            $proxyThis->addref();
            $result = $proxy->call($context, $proxyThis);
            $realObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::coerceToValuePtrForStore($context, $result)
            );
            // Zend/zend_lazy_objects.c — Z_OBJ(retval) == obj || zend_object_is_lazy (#29151).
            $sameAsProxy = $context->builder->icmp(Builder::INT_EQ, $realObj, $obj);
            $realPending = $context->builder->load(
                $context->builder->structGep($realObj, $map[VmLazyObject::FIELD_LAZY_PENDING])
            );
            $realIsLazy = $context->builder->icmp(
                Builder::INT_NE,
                $realPending,
                $i8->constInt(0, false)
            );
            $nonLazyBad = $context->builder->or($sameAsProxy, $realIsLazy);
            $nonLazyOk = $fn->appendBasicBlock('lazy_proxy_nonlazy_ok_'.$idx);
            $nonLazyFail = $fn->appendBasicBlock('lazy_proxy_nonlazy_bad_'.$idx);
            $context->builder->branchIf($nonLazyBad, $nonLazyFail, $nonLazyOk);
            $context->builder->positionAtEnd($nonLazyFail);
            self::emitProxyMustReturnNonLazyError($context);
            // Do not fall through to $done (would mark constructed). Catchable throw already
            // terminates; uncaught abort needs unreachable for LLVM verify.
            $failInsert = BasicBlockHelper::tryGetInsertBlock($context);
            if (null !== $failInsert && null === $failInsert->getTerminator()) {
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
            $context->builder->positionAtEnd($nonLazyOk);
            $realClassId = $context->builder->load(
                $context->builder->structGep($realObj, $map[VmLazyObject::FIELD_CLASS_ID])
            );
            $classMatch = $context->builder->icmp(Builder::INT_EQ, $realClassId, $classIdVal);
            $classOk = $fn->appendBasicBlock('lazy_proxy_class_ok_'.$idx);
            $classBad = $fn->appendBasicBlock('lazy_proxy_class_bad_'.$idx);
            $context->builder->branchIf($classMatch, $classOk, $classBad);
            $context->builder->positionAtEnd($classBad);
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->positionAtEnd($classOk);
            $objectType->copyInstancePropertiesFrom($obj, $realObj, $classIdVal);
            $context->builder->branch($done);

            $checkBlock = $nextBlock;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        // Idempotent — already cleared before initializer; keep constructed bit.
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_PENDING])
        );
        $objectType->markObjectConstructed($obj);
    }

    /** Mark object non-lazy before calling the initializer (Zend detach semantics). */
    private static function emitDetachLazyFlags(Context $context, Value $obj): void
    {
        $map = $context->structFieldMap['__object__'];
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_PENDING])
        );
        $context->builder->store(
            $context->getTypeFromString('int32')->constInt(-1, true),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_INIT_INDEX])
        );
    }

    /**
     * Catchable Error inside try; pending + abort when uncaught (#29151).
     *
     * @see Zend/zend_lazy_objects.c "Lazy proxy factory must return a non-lazy object"
     */
    private static function emitProxyMustReturnNonLazyError(Context $context): void
    {
        $message = 'Lazy proxy factory must return a non-lazy object';
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError($context, 'Error', $message, null);

            return;
        }
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::ensureStandaloneBodies($context);
        ErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    /**
     * Ghost initializer return must be NULL/void — TypeError otherwise (#29169).
     *
     * @see Zend/zend_lazy_objects.c "Lazy object initializer must return NULL or no value"
     */
    private static function emitGhostInitializerMustReturnNullCheck(
        Context $context,
        Value $result,
        int $idx
    ): void {
        $valuePtr = JitValueBox::coerceToValuePtrForStore($context, $result);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        $ok = $context->builder->or($isNull, $isUndef);
        $fn = BasicBlockHelper::parentFunction($context);
        $okBlock = $fn->appendBasicBlock('lazy_ghost_ret_null_ok_'.$idx);
        $badBlock = $fn->appendBasicBlock('lazy_ghost_ret_null_bad_'.$idx);
        $context->builder->branchIf($ok, $okBlock, $badBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitGhostInitializerMustReturnNullTypeError($context);
        $badInsert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null !== $badInsert && null === $badInsert->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * Catchable TypeError inside try; pending + abort when uncaught (#29169).
     */
    private static function emitGhostInitializerMustReturnNullTypeError(Context $context): void
    {
        $message = 'Lazy object initializer must return NULL or no value';
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message, null);

            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::ensureStandaloneBodies($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }
}
