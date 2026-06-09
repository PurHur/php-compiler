<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
/**
 * LLVM runtime for include_path stack + stream_resolve_include_path (issues #3223, #6051).
 */
final class IncludePathRuntime
{
    private const MAX_PATH = 4096;
    private const MAX_STACK = 16;
    private const ACCESS_F_OK = 0;

    public static function ensureLinked(Context $context): void
    {
        self::declareExternals($context);
        self::implementGlobals($context);
        self::implementGet($context);
        self::implementSet($context);
        self::implementRestore($context);
        self::implementResolve($context);
    }

    private static function declareExternals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        foreach (
            [
                'access' => [$i32, false, [$i8p, $i32]],
                'realpath' => [$i8p, false, [$i8p, $i8p]],
                'strlen' => [$sizeT, false, [$i8p]],
                'strchr' => [$i8p, false, [$i8p, $i32]],
                'memcpy' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            self::ensureExternalFunction(
                $context,
                $name,
                $context->context->functionType($ret, $vararg, ...$params)
            );
        }
    }

    private static function ensureExternalFunction(Context $context, string $name, $signature): void
    {
        if (null === $context->module->getNamedFunction($name)) {
            $context->module->addFunction($name, $signature);
        }
        $context->registerFunction($name, $context->module->getNamedFunction($name));
    }

    private static function implementGlobals(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal('phpc_include_path_depth')) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $depth = $context->module->addGlobal($i32, 'phpc_include_path_depth');
        $depth->setInitializer($i32->constInt(1, false));

        $pathTy = $i8->arrayType(self::MAX_PATH);
        $context->module->addGlobal($pathTy, 'phpc_include_path_current');

        $stackTy = $i8->arrayType(self::MAX_PATH * self::MAX_STACK);
        $context->module->addGlobal($stackTy, 'phpc_include_path_stack');

        self::implementInitDefaultPath($context);
    }

    /** Zend default include_path "." when unset (php.ini / PG(include_path)). */
    private static function implementInitDefaultPath(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_include_path_init');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_include_path_init', $probe);

            return;
        }
        $fn = $context->lookupFunction('__compiler_include_path_init');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $current = $context->module->getNamedGlobal('phpc_include_path_current');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $ptr = $context->builder->pointerCast(
            $context->builder->gep($current, $zero, $zero),
            $i8p
        );
        $first = $context->builder->load($ptr);
        $needsInit = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(0, false));
        $initBlock = $fn->appendBasicBlock('init');
        $doneBlock = $fn->appendBasicBlock('done');
        $context->builder->branchIf($needsInit, $initBlock, $doneBlock);
        $context->builder->positionAtEnd($initBlock);
        $context->builder->store($i8->constInt(ord('.'), false), $ptr);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementGet(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_get_include_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_get_include_path', $probe);

            return;
        }
        $fn = $context->lookupFunction('__compiler_get_include_path');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call($context->lookupFunction('__compiler_include_path_init'));
        $out = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $current = $context->module->getNamedGlobal('phpc_include_path_current');
        $zero = $i64->constInt(0, false);
        $currentPtr = $context->builder->pointerCast(
            $context->builder->gep($current, $zero, $zero),
            $i8p
        );
        $len = $context->builder->call($context->lookupFunction('strlen'), $currentPtr);
        $lenI64 = $context->builder->zExt($len, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $lenI64, $currentPtr);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementSet(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_set_include_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_set_include_path', $probe);

            return;
        }
        $fn = $context->lookupFunction('__compiler_set_include_path');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $newPath = $fn->getParam(0);
        $out = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $depthGlobal = $context->module->getNamedGlobal('phpc_include_path_depth');
        $depth = $context->builder->load($depthGlobal);
        $current = $context->module->getNamedGlobal('phpc_include_path_current');
        $zero = $i64->constInt(0, false);
        $currentPtr = $context->builder->pointerCast(
            $context->builder->gep($current, $zero, $zero),
            $i8p
        );
        $oldLen = $context->builder->call($context->lookupFunction('strlen'), $currentPtr);
        $oldLenI64 = $context->builder->zExt($oldLen, $i64);
        $oldStr = $context->builder->call($context->lookupFunction('__string__init'), $oldLenI64, $currentPtr);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $oldStr);

        $stack = $context->module->getNamedGlobal('phpc_include_path_stack');
        $slotIndex = $context->builder->mul(
            $context->builder->zExt($depth, $i64),
            $i64->constInt(self::MAX_PATH, false)
        );
        $slotPtr = $context->builder->pointerCast(
            $context->builder->gep($stack, $zero, $slotIndex),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $slotPtr,
            $currentPtr,
            $sizeT->constInt(self::MAX_PATH, false)
        );
        $context->builder->store(
            $context->builder->add($depth, $i32->constInt(1, false)),
            $depthGlobal
        );

        $newLen = $context->builder->load($context->builder->structGep($newPath, $strMap['length']));
        $newBytes = $context->builder->structGep($newPath, $strMap['value']);
        $maxCopy = $i64->constInt(self::MAX_PATH - 1, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $newLen, $maxCopy),
            $newLen,
            $maxCopy
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $currentPtr,
            $newBytes,
            $context->builder->truncOrBitCast($copyLen, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($currentPtr, $copyLen));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementRestore(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_restore_include_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_restore_include_path', $probe);

            return;
        }
        $fn = $context->lookupFunction('__compiler_restore_include_path');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $depthGlobal = $context->module->getNamedGlobal('phpc_include_path_depth');
        $depth = $context->builder->load($depthGlobal);
        $canPop = $context->builder->icmp(Builder::INT_SGT, $depth, $i32->constInt(1, false));
        $done = $fn->appendBasicBlock('done');
        $pop = $fn->appendBasicBlock('pop');
        $context->builder->branchIf($canPop, $pop, $done);

        $context->builder->positionAtEnd($pop);
        $newDepth = $context->builder->sub($depth, $i32->constInt(1, false));
        $context->builder->store($newDepth, $depthGlobal);
        $stack = $context->module->getNamedGlobal('phpc_include_path_stack');
        $zero = $i64->constInt(0, false);
        $slotIndex = $context->builder->mul(
            $context->builder->zExt($newDepth, $i64),
            $i64->constInt(self::MAX_PATH, false)
        );
        $slotPtr = $context->builder->pointerCast(
            $context->builder->gep($stack, $zero, $slotIndex),
            $i8p
        );
        $current = $context->module->getNamedGlobal('phpc_include_path_current');
        $currentPtr = $context->builder->pointerCast(
            $context->builder->gep($current, $zero, $zero),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $currentPtr,
            $slotPtr,
            $sizeT->constInt(self::MAX_PATH, false)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementResolve(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_resolve_include_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_stream_resolve_include_path', $probe);

            return;
        }
        $fn = $context->lookupFunction('__compiler_stream_resolve_include_path');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call($context->lookupFunction('__compiler_include_path_init'));

        $filename = $fn->getParam(0);
        $strMap = $context->structFieldMap['__string__'];
        $strPtrTy = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nullStr = $strPtrTy->constNull();
        $zeroI32 = $i32->constInt(0, false);

        $pathBuf = $context->builder->alloca($i8, $i64->constInt(self::MAX_PATH, false), 'resolve_path');
        $pathCStr = $context->builder->pointerCast($pathBuf, $i8p);
        $realBuf = $context->builder->alloca($i8, $i64->constInt(self::MAX_PATH, false), 'resolve_real');
        $realCStr = $context->builder->pointerCast($realBuf, $i8p);

        $nameLen = $context->builder->load($context->builder->structGep($filename, $strMap['length']));
        $nameBytes = $context->builder->structGep($filename, $strMap['value']);
        $empty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $i64->constInt(0, false));
        $failBlock = BasicBlockHelper::append($context, 'resolve_fail');
        $absBlock = BasicBlockHelper::append($context, 'resolve_abs');
        $context->builder->branchIf($empty, $failBlock, $absBlock);

        $context->builder->positionAtEnd($absBlock);
        $first = $context->builder->load($nameBytes);
        $isAbs = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(ord('/'), false));
        $absTry = BasicBlockHelper::append($context, 'resolve_abs_try');
        $searchBlock = BasicBlockHelper::append($context, 'resolve_search');
        $context->builder->branchIf($isAbs, $absTry, $searchBlock);

        $context->builder->positionAtEnd($absTry);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $pathCStr,
            $nameBytes,
            $context->builder->truncOrBitCast($nameLen, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($pathCStr, $nameLen));
        $resolved = $context->builder->call($context->lookupFunction('realpath'), $pathCStr, $realCStr);
        $resolvedNull = $context->builder->icmp(Builder::INT_EQ, $resolved, $i8p->constNull());
        $absOk = BasicBlockHelper::append($context, 'resolve_abs_ok');
        $context->builder->branchIf($resolvedNull, $failBlock, $absOk);
        $context->builder->positionAtEnd($absOk);
        $context->builder->returnValue(self::stringFromCstr($context, $resolved));

        $context->builder->positionAtEnd($searchBlock);
        $current = $context->module->getNamedGlobal('phpc_include_path_current');
        $zero = $i64->constInt(0, false);
        $includePtr = $context->builder->pointerCast(
            $context->builder->gep($current, $zero, $zero),
            $i8p
        );
        $idxSlot = $context->builder->alloca($i64, 1, 'resolve_idx');
        $context->builder->store($zero, $idxSlot);
        $incLen = $context->builder->call($context->lookupFunction('strlen'), $includePtr);
        $incLenI64 = $context->builder->zExt($incLen, $i64);
        $loopHead = BasicBlockHelper::append($context, 'resolve_loop');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $incLenI64);
        $dirBlock = BasicBlockHelper::append($context, 'resolve_dir');
        $context->builder->branchIf($pastEnd, $failBlock, $dirBlock);

        $context->builder->positionAtEnd($dirBlock);
        $dirStart = $context->builder->gep($includePtr, $idx);
        $sepPtr = $context->builder->call(
            $context->lookupFunction('strchr'),
            $dirStart,
            $i32->constInt(ord(':'), false)
        );
        $sepNull = $context->builder->icmp(Builder::INT_EQ, $sepPtr, $i8p->constNull());
        $dirEnd = $context->builder->select(
            $sepNull,
            $context->builder->gep($includePtr, $incLenI64),
            $sepPtr
        );
        $dirLen = $context->builder->sub(
            $context->builder->ptrToInt($dirEnd, $i64),
            $context->builder->ptrToInt($dirStart, $i64)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $pathCStr,
            $dirStart,
            $context->builder->truncOrBitCast($dirLen, $sizeT)
        );
        $context->builder->store($i8->constInt(ord('/'), false), $context->builder->gep($pathCStr, $dirLen));
        $afterSlash = $context->builder->add($dirLen, $i64->constInt(1, false));
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->gep($pathCStr, $afterSlash),
            $nameBytes,
            $context->builder->truncOrBitCast($nameLen, $sizeT)
        );
        $totalLen = $context->builder->add($afterSlash, $nameLen);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($pathCStr, $totalLen));

        $exists = $context->builder->call(
            $context->lookupFunction('access'),
            $pathCStr,
            $i32->constInt(self::ACCESS_F_OK, false)
        );
        $found = $context->builder->icmp(Builder::INT_EQ, $exists, $zeroI32);
        $tryReal = BasicBlockHelper::append($context, 'resolve_try_real');
        $nextDir = BasicBlockHelper::append($context, 'resolve_next');
        $context->builder->branchIf($found, $tryReal, $nextDir);

        $context->builder->positionAtEnd($tryReal);
        $resolved = $context->builder->call($context->lookupFunction('realpath'), $pathCStr, $realCStr);
        $resolvedNull = $context->builder->icmp(Builder::INT_EQ, $resolved, $i8p->constNull());
        $foundOk = BasicBlockHelper::append($context, 'resolve_found');
        $context->builder->branchIf($resolvedNull, $nextDir, $foundOk);
        $context->builder->positionAtEnd($foundOk);
        $context->builder->returnValue(self::stringFromCstr($context, $resolved));

        $context->builder->positionAtEnd($nextDir);
        $nextIdx = $context->builder->select(
            $sepNull,
            $context->builder->add($incLenI64, $i64->constInt(1, false)),
            $context->builder->add(
                $idx,
                $context->builder->add($dirLen, $i64->constInt(1, false))
            )
        );
        $context->builder->store($nextIdx, $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($nullStr);
        $context->builder->clearInsertionPosition();
    }

    private static function stringFromCstr(Context $context, $cstr)
    {
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
    }
}
