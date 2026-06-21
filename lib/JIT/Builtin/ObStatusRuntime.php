<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ObStackLimits;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ob_get_status() / ob_list_handlers() via ObStatusJitHelper PHP (#9497).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmOb}
 * php-src: ext/standard/output.c — PHP_FUNCTION(ob_get_status)
 */
final class ObStatusRuntime
{
    private const HANDLER_NAME = 'default output handler';

    private const HELPER_PATH = '/ext/standard/ObStatusJitHelper.php';

    private const BUILD_STATUS_PARTIAL = 'PHPCompiler\\ext\\standard\\ObStatusJitHelper::buildStatusEntryPartial';

    private const FN_GET_STATUS = '__phpc_ob_get_status_ht';

    private const FN_LIST_HANDLERS = '__phpc_ob_list_handlers_ht';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILD_STATUS_PARTIAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FN_GET_STATUS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $listProbe = $context->module->getNamedFunction(self::FN_LIST_HANDLERS);
            if (null !== $listProbe && $listProbe->countBasicBlocks() > 0) {
                self::registerLinkedRuntime($context);

                return;
            }
        }

        ObOutput::registerExternals($context);
        self::ensureBufferUsedDecl($context);
        self::ensureHashtableHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::registerDeclarations($context);
        self::implementGetStatusBridge($context);
        self::implementListHandlers($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function registerDeclarations(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        self::declareIfMissing(
            $context,
            self::FN_GET_STATUS,
            $context->context->functionType($htPtr, false, $i32)
        );
        self::declareIfMissing(
            $context,
            self::FN_LIST_HANDLERS,
            $context->context->functionType($htPtr, false)
        );
    }

    private static function declareIfMissing(Context $context, string $name, $ft): void
    {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);
    }

    private static function implementGetStatusBridge(Context $context): void
    {
        $abiName = self::FN_GET_STATUS;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($htPtr, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ogs_bridge_entry');
        $emptyBb = $fn->appendBasicBlock('ogs_empty');
        $routeBb = $fn->appendBasicBlock('ogs_route');
        $topBb = $fn->appendBasicBlock('ogs_top');
        $fullBb = $fn->appendBasicBlock('ogs_full');
        $context->builder->positionAtEnd($entry);

        $fullArg = $fn->getParam(0);
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
            $context->builder->trunc($fullArg, $i32),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($wantFull, $fullBb, $topBb);

        $context->builder->positionAtEnd($topBb);
        $activeIdx = $context->builder->sub($level, $i32->constInt(1, false));
        $used = self::emitBufferUsed($context, $activeIdx);
        $topHt = self::emitStatusEntryWithName($context, $activeIdx, $used);
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
        $used = self::emitBufferUsed($context, $iLoad);
        $entryHt = self::emitStatusEntryWithName($context, $iLoad, $used);
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
        $context->registerFunction($abiName, $fn);
    }

    private static function emitStatusEntryWithName(Context $context, Value $levelIdx, Value $bufferUsed): Value
    {
        $ht = $context->builder->call(
            self::helperFunction($context, self::BUILD_STATUS_PARTIAL),
            $context->builder->sext($levelIdx, $context->getTypeFromString('int64')),
            $bufferUsed
        );
        self::attachHandlerName($context, $ht);

        return $ht;
    }

    private static function attachHandlerName(Context $context, Value $ht): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            self::literalKeyString($context, 'name'),
            self::literalValueString($context, self::HANDLER_NAME)
        );
    }

    private static function implementListHandlers(Context $context): void
    {
        $abiName = self::FN_LIST_HANDLERS;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('olh_entry');
        $emptyBb = $fn->appendBasicBlock('olh_empty');
        $loopInit = $fn->appendBasicBlock('olh_loop_init');
        $loopCond = $fn->appendBasicBlock('olh_loop_cond');
        $loopBody = $fn->appendBasicBlock('olh_loop_body');
        $loopInc = $fn->appendBasicBlock('olh_loop_inc');
        $loopDone = $fn->appendBasicBlock('olh_loop_done');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $level = $context->builder->call($context->lookupFunction('__phpc_ob_get_level'));
        $hasBuffers = $context->builder->icmp(
            Builder::INT_NE,
            $level,
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($hasBuffers, $loopInit, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        $context->builder->positionAtEnd($loopInit);
        $list = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $handlerName = self::literalValueString($context, self::HANDLER_NAME);
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $iVar = $context->builder->alloca($i32, 'olh_i');
        $context->builder->store($i32->constInt(0, false), $iVar);
        $context->builder->branch($loopCond);

        $context->builder->positionAtEnd($loopCond);
        $iLoad = $context->builder->load($iVar);
        $continueLoop = $context->builder->icmp(Builder::INT_SLT, $iLoad, $level);
        $context->builder->branchIf($continueLoop, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $context->builder->call(
            $setStringAt,
            $list,
            $context->builder->pointerCast($context->builder->sext($iLoad, $i64), $sizeT),
            $handlerName
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
        $context->registerFunction($abiName, $fn);
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

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setHashtableAt',
            $context->context->functionType($voidTy, false, $htPtr, $sizeT, $htPtr)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringAt',
            $context->context->functionType($voidTy, false, $htPtr, $sizeT, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyString',
            $context->context->functionType($voidTy, false, $htPtr, $strPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ObStatusJitHelper compile (#9497)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ObStatusJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ObStatusJitHelper.php parseAndCompile failed (#9497)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9497)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::FN_GET_STATUS, self::FN_LIST_HANDLERS] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ObStatusRuntime bridge (#9497)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
