<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ObStackLimits;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM ob_get_status() metadata from OB stack globals (issue #5609, #3647).
 *
 * php-src: ext/standard/output.c — PHP_FUNCTION(ob_get_status)
 */
final class ObStatusRuntime
{
    private const HANDLER_NAME = 'default output handler';

    private const HANDLER_TYPE = 0;

    private const HANDLER_FLAGS = 112;

    private const DEFAULT_BUFFER_SIZE = 16384;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_ob_get_status_ht');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        ObOutput::registerExternals($context);
        self::ensureBufferUsedDecl($context);
        self::ensureHashtableHelpers($context);

        $i32 = $context->getTypeFromString('int32');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $entryProbe = $context->module->getNamedFunction('__phpc_ob_status_entry');
        $ftEntry = $context->context->functionType($htPtr, false, $i32);
        $fnEntry = null !== $entryProbe
            ? $entryProbe
            : $context->module->addFunction('__phpc_ob_status_entry', $ftEntry);
        self::implementStatusEntry($context, $fnEntry);

        $statusProbe = $context->module->getNamedFunction('__phpc_ob_get_status_ht');
        $ftStatus = $context->context->functionType($htPtr, false, $i32);
        $fnStatus = null !== $statusProbe
            ? $statusProbe
            : $context->module->addFunction('__phpc_ob_get_status_ht', $ftStatus);
        self::implementGetStatus($context, $fnStatus, $fnEntry);

        self::registerLinkedRuntime($context);
    }

    private static function implementStatusEntry(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('ose_entry');
        $context->builder->positionAtEnd($entry);

        $levelIdx = $fn->getParam(0);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $used = self::emitBufferUsed($context, $levelIdx);

        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $i64 = $context->getTypeFromString('int64');

        $context->builder->call(
            $setString,
            $ht,
            self::literalKeyString($context, 'name'),
            self::literalValueString($context, self::HANDLER_NAME)
        );
        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'type'),
            $i64->constInt(self::HANDLER_TYPE, false)
        );
        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'flags'),
            $i64->constInt(self::HANDLER_FLAGS, false)
        );
        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'level'),
            $context->builder->sext($levelIdx, $i64)
        );
        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'chunk_size'),
            $i64->constInt(0, false)
        );
        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'buffer_size'),
            $i64->constInt(self::DEFAULT_BUFFER_SIZE, false)
        );
        $context->builder->call(
            $setLong,
            $ht,
            self::literalKeyString($context, 'buffer_used'),
            $used
        );

        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetStatus(Context $context, Value $fn, Value $fnEntry): void
    {
        $entry = $fn->appendBasicBlock('ogs_entry');
        $emptyBb = $fn->appendBasicBlock('ogs_empty');
        $routeBb = $fn->appendBasicBlock('ogs_route');
        $topBb = $fn->appendBasicBlock('ogs_top');
        $fullBb = $fn->appendBasicBlock('ogs_full');
        $context->builder->positionAtEnd($entry);

        $full = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $level = $context->builder->call($context->lookupFunction('__phpc_ob_get_level'));
        $hasBuffers = $context->builder->icmp(
            Builder::INT_NE,
            $level,
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($hasBuffers, $routeBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));
        $context->builder->positionAtEnd($routeBb);

        $wantFull = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->trunc($full, $i32),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($wantFull, $fullBb, $topBb);

        $context->builder->positionAtEnd($topBb);
        $activeIdx = $context->builder->sub($level, $i32->constInt(1, false));
        $topHt = $context->builder->call($fnEntry, $activeIdx);
        $context->builder->returnValue($topHt);

        $context->builder->positionAtEnd($fullBb);
        $list = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setAt = $context->lookupFunction('__hashtable__setHashtableAt');

        $loopInit = $fn->appendBasicBlock('ogs_loop_init');
        $loopCond = $fn->appendBasicBlock('ogs_loop_cond');
        $loopBody = $fn->appendBasicBlock('ogs_loop_body');
        $loopInc = $fn->appendBasicBlock('ogs_loop_inc');
        $loopDone = $fn->appendBasicBlock('ogs_loop_done');

        $context->builder->branch($loopInit);
        $context->builder->positionAtEnd($loopInit);
        $iVar = $context->builder->alloca($i32, 'ogs_i');
        $context->builder->store($i32->constInt(0, false), $iVar);
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopCond);
        $iLoad = $context->builder->load($iVar);
        $continueLoop = $context->builder->icmp(Builder::INT_SLT, $iLoad, $level);
        $context->builder->branchIf($continueLoop, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $entryHt = $context->builder->call($fnEntry, $iLoad);
        $context->builder->call(
            $setAt,
            $list,
            $context->builder->pointerCast($context->builder->sext($iLoad, $i64), $sizeT),
            $entryHt
        );
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($iLoad, $i32->constInt(1, false)),
            $iVar
        );
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($list);
        $context->builder->clearInsertionPosition();
    }

    private static function emitBufferUsed(Context $context, Value $levelIdx): Value
    {
        $i64 = $context->getTypeFromString('int64');

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return $context->builder->call(
                $context->lookupFunction('__phpc_ob_buffer_used_at'),
                $context->builder->sext($levelIdx, $i64)
            );
        }

        ObStorageGlobals::ensureGlobals($context);
        $lenGlobal = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_LEN);
        if (null === $lenGlobal) {
            throw new \LogicException('ObStatusRuntime: '.ObStorageGlobals::GLOBAL_LEN.' missing');
        }
        $lenPtr = $context->builder->pointerCast(
            $lenGlobal,
            $i64->arrayType(ObStackLimits::MAX_DEPTH)->pointerType(0)
        );
        $elem = $context->builder->inBoundsGEP(
            $lenPtr,
            $i64->constInt(0, false),
            $context->builder->sext($levelIdx, $i64)
        );

        return $context->builder->load($elem);
    }

    private static function ensureBufferUsedDecl(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        self::ensureExternal(
            $context,
            '__phpc_ob_buffer_used_at',
            $context->context->functionType($i64, false, $i64)
        );
    }

    private static function literalKeyString(Context $context, string $text): Value
    {
        return self::literalValueString($context, $text);
    }

    private static function literalValueString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyLong',
            $context->context->functionType($voidTy, false, $htPtr, $strPtr, $i64)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyString',
            $context->context->functionType($voidTy, false, $htPtr, $strPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setHashtableAt',
            $context->context->functionType($voidTy, false, $htPtr, $sizeT, $htPtr)
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $context->getTypeFromString('int8*'))
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__phpc_ob_status_entry', '__phpc_ob_get_status_ht'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ObStatusRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
