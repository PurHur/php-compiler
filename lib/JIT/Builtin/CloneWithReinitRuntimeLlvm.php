<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Standalone AOT LLVM clone-with reinit window (#7250, #9498).
 *
 * JIT embed uses {@see CloneWithReinitRuntime} + {@see CloneWithJitHelper} instead.
 */
final class CloneWithReinitRuntimeLlvm
{
    private const MAX_PROPS = 16;

    private const NAME_MAX = 64;

    public static function ensureLinked(Context $context): void
    {
        self::registerGlobals($context);
        self::registerDeclarations($context);
        self::implementBodies($context);
    }

    /** @param list<string> $names */
    public static function emitBegin(Context $context, Value $obj, array $names): void
    {
        self::ensureLinked($context);
        $count = \count($names);
        if ($count > self::MAX_PROPS) {
            throw new \LogicException('phpc_clone_with_begin() supports at most '.self::MAX_PROPS.' properties');
        }
        $i32 = $context->getTypeFromString('int32');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $active = $context->module->getNamedGlobal('phpc_clone_with_active_obj');
        $context->builder->store($obj, $context->builder->pointerCast($active, $objPtrTy));
        $countGlobal = $context->module->getNamedGlobal('phpc_clone_with_prop_count');
        $context->builder->store(
            $i32->constInt($count, false),
            $context->builder->pointerCast($countGlobal, $i32->pointerType(0))
        );
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        foreach ($names as $i => $name) {
            $len = \strlen($name);
            if ($len >= self::NAME_MAX) {
                throw new \LogicException('clone-with property name too long for JIT reinit window');
            }
            $slotGlobal = $context->module->getNamedGlobal('phpc_clone_with_prop_'.$i);
            $lenGlobal = $context->module->getNamedGlobal('phpc_clone_with_prop_len_'.$i);
            $namePtr = $context->pointerFromStringConstant($name);
            $slotPtr = $context->builder->pointerCast($slotGlobal, $i8p);
            $context->intrinsic->memcpy(
                $slotPtr,
                $namePtr,
                $context->constantFromInteger($len, 'size_t'),
                false
            );
            $term = $context->builder->inBoundsGEP($slotPtr, $context->constantFromInteger($len, 'size_t'));
            $context->builder->store($i8->constInt(0, false), $term);
            $context->builder->store(
                $i32->constInt($len, false),
                $context->builder->pointerCast($lenGlobal, $i32->pointerType(0))
            );
        }
    }

    public static function emitEnd(Context $context, Value $obj): void
    {
        self::ensureLinked($context);
        $context->builder->call($context->lookupFunction('phpc_clone_with_end_runtime'), $obj);
    }

    public static function emitTryConsumePropertyName(Context $context, Value $obj, string $propName): Value
    {
        self::ensureLinked($context);
        $namePtr = $context->constantFromString($propName);
        $len = \strlen($propName);

        return $context->builder->call(
            $context->lookupFunction('phpc_clone_with_try_consume_literal'),
            $obj,
            $context->builder->pointerCast($namePtr, $context->getTypeFromString('int8*')),
            $context->getTypeFromString('int32')->constInt($len, false)
        );
    }

    private static function registerGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $objPtrTy = $context->getTypeFromString('__object__*');
        if (null === $context->module->getNamedGlobal('phpc_clone_with_active_obj')) {
            $active = $context->module->addGlobal($objPtrTy, 'phpc_clone_with_active_obj');
            $active->setInitializer($objPtrTy->constNull());
        }
        if (null === $context->module->getNamedGlobal('phpc_clone_with_prop_count')) {
            $count = $context->module->addGlobal($i32, 'phpc_clone_with_prop_count');
            $count->setInitializer($i32->constInt(0, false));
        }
        for ($i = 0; $i < self::MAX_PROPS; ++$i) {
            $slotName = 'phpc_clone_with_prop_'.$i;
            if (null === $context->module->getNamedGlobal($slotName)) {
                $slot = $context->module->addGlobal($i8->arrayType(self::NAME_MAX), $slotName);
                $slot->setInitializer($i8->arrayType(self::NAME_MAX)->constNull());
            }
            $lenName = 'phpc_clone_with_prop_len_'.$i;
            if (null === $context->module->getNamedGlobal($lenName)) {
                $len = $context->module->addGlobal($i32, $lenName);
                $len->setInitializer($i32->constInt(0, false));
            }
        }
    }

    private static function registerDeclarations(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $objPtr = $context->getTypeFromString('__object__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');

        self::declareIfMissing($context, 'phpc_clone_with_end_runtime', $void, [$objPtr]);
        self::declareIfMissing($context, 'phpc_clone_with_try_consume_literal', $i1, [$objPtr, $i8p, $i32]);
    }

    private static function declareIfMissing(Context $context, string $name, $ret, array $params): void
    {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $ft = $context->context->functionType($ret, false, ...$params);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);
    }

    private static function implementBodies(Context $context): void
    {
        self::implementEnd($context);
        self::implementTryConsumeLiteral($context);
    }

    private static function implementEnd(Context $context): void
    {
        $fn = $context->module->getNamedFunction('phpc_clone_with_end_runtime');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i32 = $context->getTypeFromString('int32');
        $active = $context->module->getNamedGlobal('phpc_clone_with_active_obj');
        $activePtr = $context->builder->pointerCast($active, $objPtrTy->pointerType(0));
        $loaded = $context->builder->load($activePtr);
        $same = $context->builder->icmp(Builder::INT_EQ, $loaded, $obj);
        $clearBlock = $fn->appendBasicBlock('clear');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branchIf($same, $clearBlock, $done);
        $context->builder->positionAtEnd($clearBlock);
        $context->builder->store($objPtrTy->constNull(), $activePtr);
        $countGlobal = $context->module->getNamedGlobal('phpc_clone_with_prop_count');
        $context->builder->store(
            $i32->constInt(0, false),
            $context->builder->pointerCast($countGlobal, $i32->pointerType(0))
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementTryConsumeLiteral(Context $context): void
    {
        $fn = $context->module->getNamedFunction('phpc_clone_with_try_consume_literal');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $name = $fn->getParam(1);
        $len = $fn->getParam(2);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $active = $context->module->getNamedGlobal('phpc_clone_with_active_obj');
        $activePtr = $context->builder->pointerCast($active, $objPtrTy->pointerType(0));
        $loadedObj = $context->builder->load($activePtr);
        $sameObj = $context->builder->icmp(Builder::INT_EQ, $loadedObj, $obj);
        $fail = $fn->appendBasicBlock('fail');
        $loopInit = $fn->appendBasicBlock('loop_init');
        $loopHeader = $fn->appendBasicBlock('loop_header');
        $context->builder->branchIf($sameObj, $loopInit, $fail);

        $countGlobal = $context->module->getNamedGlobal('phpc_clone_with_prop_count');
        $countPtr = $context->builder->pointerCast($countGlobal, $i32->pointerType(0));

        $context->builder->positionAtEnd($loopInit);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($loopHeader);
        $idxPhi = $context->builder->phi($i32);
        $idxPhi->addIncoming($i32->constInt(0, false), $loopInit);
        $loopBody = $fn->appendBasicBlock('loop_body');
        $loopDone = $fn->appendBasicBlock('loop_done');
        $count = $context->builder->load($countPtr);
        $continueLoop = $context->builder->icmp(Builder::INT_SLT, $idxPhi, $count);
        $context->builder->branchIf($continueLoop, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $idx = $idxPhi;
        $slotLenPtr = self::indexedLenPtr($context, $idx);
        $slotLen = $context->builder->load($slotLenPtr);
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $slotLen, $len);
        $cmpBlock = $fn->appendBasicBlock('cmp_name');
        $next = $fn->appendBasicBlock('next');
        $context->builder->branchIf($lenEq, $cmpBlock, $next);

        $context->builder->positionAtEnd($cmpBlock);
        $slotNamePtr = self::indexedNamePtr($context, $idx);
        $memcmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $slotNamePtr,
            $name,
            $context->builder->zext($len, $sizeT)
        );
        $eq = $context->builder->icmp(Builder::INT_EQ, $memcmp, $i32->constInt(0, false));
        $consume = $fn->appendBasicBlock('consume');
        $context->builder->branchIf($eq, $consume, $next);

        $context->builder->positionAtEnd($consume);
        $lastIdx = $context->builder->sub($count, $i32->constInt(1, false));
        $lastLenPtr = self::indexedLenPtr($context, $lastIdx);
        $lastNamePtr = self::indexedNamePtr($context, $lastIdx);
        $lastLen = $context->builder->load($lastLenPtr);
        $context->builder->store($lastLen, $slotLenPtr);
        $context->intrinsic->memcpy(
            $slotNamePtr,
            $lastNamePtr,
            $context->builder->zext($lastLen, $sizeT),
            false
        );
        $term = $context->builder->inBoundsGEP($slotNamePtr, $context->builder->zext($lastLen, $sizeT));
        $context->builder->store($i8->constInt(0, false), $term);
        $context->builder->store($context->builder->sub($count, $i32->constInt(1, false)), $countPtr);
        $ok = $fn->appendBasicBlock('ok');
        $context->builder->branch($ok);
        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($i1->constInt(1, false));

        $context->builder->positionAtEnd($next);
        $nextIdx = $context->builder->add($idx, $i32->constInt(1, false));
        $idxPhi->addIncoming($nextIdx, $next);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i1->constInt(0, false));
        $context->builder->clearInsertionPosition();
    }

    private static function indexedNamePtr(Context $context, Value $idx): Value
    {
        return self::indexedPtr($context, $idx, 'phpc_clone_with_prop_', $context->getTypeFromString('int8*'));
    }

    private static function indexedLenPtr(Context $context, Value $idx): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return self::indexedPtr($context, $idx, 'phpc_clone_with_prop_len_', $i32->pointerType(0));
    }

    private static function indexedPtr(Context $context, Value $idx, string $prefix, $ptrTy): Value
    {
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);
        $done = $fn->appendBasicBlock($prefix.'sel_done');
        $default = $fn->appendBasicBlock($prefix.'sel_default');
        $i32 = $context->getTypeFromString('int32');
        $switch = $context->builder->branchSwitch($idx, $default, self::MAX_PROPS);
        $incoming = [];
        for ($i = 0; $i < self::MAX_PROPS; ++$i) {
            $case = $fn->appendBasicBlock($prefix.'sel_'.$i);
            $switch->addCase($i32->constInt($i, false), $case);
            $context->builder->positionAtEnd($case);
            $global = $context->module->getNamedGlobal($prefix.$i);
            $ptr = $context->builder->pointerCast($global, $ptrTy);
            $incoming[] = [$ptr, $case];
            $context->builder->branch($done);
        }
        $context->builder->positionAtEnd($default);
        $fallback = $context->builder->pointerCast(
            $context->module->getNamedGlobal($prefix.'0'),
            $ptrTy
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($ptrTy);
        foreach ($incoming as [$ptr, $block]) {
            $phi->addIncoming($ptr, $block);
        }
        $phi->addIncoming($fallback, $default);

        return $phi;
    }
}
