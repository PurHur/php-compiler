<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
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

    public static function emitEnsureInitialized(Context $context, Value $obj): void
    {
        if ([] === $context->lazyInitProxies) {
            return;
        }

        $map = $context->structFieldMap['__object__'];
        $i32 = $context->getTypeFromString('int32');
        $pending = $context->builder->load(
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_PENDING])
        );
        $isPending = $context->builder->icmp(
            Builder::INT_NE,
            $pending,
            $i32->constInt(0, false)
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

        $i32 = $context->getTypeFromString('int32');
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
                $i32->constInt(0, false)
            );
            $ghostBlock = $fn->appendBasicBlock('lazy_init_ghost_'.$idx);
            $proxyBlock = $fn->appendBasicBlock('lazy_init_proxy_body_'.$idx);
            $context->builder->branchIf($isGhost, $ghostBlock, $proxyBlock);

            $context->builder->positionAtEnd($ghostBlock);
            $objectType->resetInstancePropertySlots($obj, $classIdVal);
            $objectType->applyLazyGhostPropertyDefaults($obj, $classIdVal);
            $thisVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            $thisVar->addref();
            $proxy->call($context, $thisVar);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($proxyBlock);
            $proxyThis = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            $proxyThis->addref();
            $result = $proxy->call($context, $proxyThis);
            $realObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::coerceToValuePtrForStore($context, $result)
            );
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
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_PENDING])
        );
        $objectType->markObjectConstructed($obj);
    }
}
