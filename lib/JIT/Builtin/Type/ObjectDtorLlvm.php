<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM;
use PHPLLVM\Builder;

/**
 * Release {@see __object__} property slots before the header is freed (#36215).
 *
 * php-src: zend_object_std_dtor — zval_ptr_dtor on each property, then free object store.
 */
final class ObjectDtorLlvm
{
    public static function register(Object_ $object): void
    {
        $context = $object->jitContext();
        $void = $context->getTypeFromString('void');
        $objPtr = $context->getTypeFromString('__object__*');
        $ft = $context->context->functionType($void, false, $objPtr);
        $fn = $context->module->addFunction('__object__dtor', $ft);
        $context->registerFunction('__object__dtor', $fn);
    }

    public static function implement(Object_ $object): void
    {
        $context = $object->jitContext();
        $fn = $context->module->getNamedFunction('__object__dtor');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $objMap = $context->structFieldMap['__object__'];
        $propCount = $context->builder->load(
            $context->builder->structGep($obj, $objMap['prop_count'])
        );

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $voidPtr = $context->getTypeFromString('void*');
        $voidNull = $voidPtr->constNull();
        $refVirtual = $context->getTypeFromString('__ref__virtual*');
        $delref = $context->lookupFunction('__ref__delref');
        $valueDelref = $context->lookupFunction('__value__valueDelref');
        $refMap = $context->structFieldMap['__ref__'];
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');

        $loopHead = $fn->appendBasicBlock('obj_dtor_head');
        $loopBody = $fn->appendBasicBlock('obj_dtor_body');
        $loopDone = $fn->appendBasicBlock('obj_dtor_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $slotIdx = $context->builder->phi($sizeT);
        $slotIdx->addIncoming($zero, $entry);
        $propCountExt = $context->builder->zext($propCount, $sizeT);
        $doneLoop = $context->builder->icmp(Builder::INT_UGE, $slotIdx, $propCountExt);
        $context->builder->branchIf($doneLoop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $i8p = $context->getTypeFromString('int8*');
        $voidpp = $context->getTypeFromString('void**');
        $cast = $context->builder->pointerCast($obj, $i8p);
        $headerBytes = $context->builder->ptrToInt(
            $context->builder->gep(
                $context->getTypeFromString('__object__')->pointerType(0)->constNull(),
                $context->context->int32Type()->constInt(1, false)
            ),
            $sizeT
        );
        $slotOff = $context->builder->add(
            $headerBytes,
            $context->builder->mul($slotIdx, $sizeT->constInt(8, false))
        );
        $dynSlotPtr = $context->builder->pointerCast(
            $context->builder->gep($cast, $slotOff),
            $voidpp
        );
        $content = $context->builder->pointerCast(
            $context->builder->load($dynSlotPtr),
            $voidPtr
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $content, $voidNull);
        $release = $fn->appendBasicBlock('obj_dtor_release');
        $advance = $fn->appendBasicBlock('obj_dtor_advance');
        $context->builder->branchIf($isNull, $advance, $release);

        $context->builder->positionAtEnd($release);
        $tagHead = $context->builder->pointerCast($content, $i8->pointerType(0));
        $tag = $context->builder->load($tagHead);
        $hasRefTag = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($tag, $i8->constInt(0x80, false)),
            $i8->constInt(0, false)
        );
        $head = $context->builder->pointerCast($content, $context->getTypeFromString('__ref__*'));
        $typeinfo = $context->builder->load($context->builder->structGep($head, $refMap['typeinfo']));
        $masked = $context->builder->and($typeinfo, $i32->constInt(0xFFFFFFFC, false));
        $isRefHeader = $context->builder->icmp(
            Builder::INT_NE,
            $masked,
            $i32->constInt(0, false)
        );
        $refRelease = $fn->appendBasicBlock('obj_dtor_ref_release');
        $nativeRelease = $fn->appendBasicBlock('obj_dtor_native_release');
        $nativeFree = $fn->appendBasicBlock('obj_dtor_native_free');
        $boxRelease = $fn->appendBasicBlock('obj_dtor_box_release');
        $afterRelease = $fn->appendBasicBlock('obj_dtor_after_release');
        $context->builder->branchIf($isRefHeader, $refRelease, $nativeRelease);

        $context->builder->positionAtEnd($nativeRelease);
        $context->builder->branchIf($hasRefTag, $boxRelease, $nativeFree);
        $context->builder->positionAtEnd($nativeFree);
        $context->memory->free($content);
        $context->builder->branch($afterRelease);

        $context->builder->positionAtEnd($refRelease);
        $context->builder->call(
            $delref,
            $context->builder->pointerCast($content, $refVirtual)
        );
        $context->builder->branch($afterRelease);

        $context->builder->positionAtEnd($boxRelease);
        $valuePtr = $context->builder->pointerCast($content, $context->getTypeFromString('__value__*'));
        $context->builder->call($valueDelref, $valuePtr);
        $context->memory->free($content);
        $context->builder->branch($afterRelease);

        $context->builder->positionAtEnd($afterRelease);
        $context->builder->store($voidNull, $dynSlotPtr);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $nextIdx = $context->builder->addNoSignedWrap($slotIdx, $one);
        $context->builder->branch($loopHead);
        $slotIdx->addIncoming($nextIdx, $advance);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
