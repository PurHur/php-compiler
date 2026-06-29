<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ObStackLimits;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM ob_gzhandler ABI for AOT standalone (#9798).
 *
 * Accept-Encoding sg_SERVER walk stays LLVM until HashTable::find compiles in standalone nested link.
 * Embed/MCJIT routes through {@see ObGzhandlerJitHelper} PHP via {@see ObGzhandlerJitRuntime}.
 * php-src: ext/zlib/zlib.c — php_ob_gzhandler
 */
final class ObGzhandlerStandaloneLlvm
{
    private const GLOBAL_HANDLER = '__phpc_ob_handler';

    private static int $blockSuffix = 0;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_ob_gzhandler',
        '__phpc_ob_gzhandler_flush',
        '__phpc_ob_start_with_gzhandler',
    ];

    public static function implement(Context $context): void
    {
        self::$blockSuffix = 0;
        self::ensureGlobals($context);
        self::ensureServerReadHelpers($context);
        self::implementObGzhandlerBridge($context);
        self::implementGzhandlerFlushBridge($context);
        self::implementObStartWithGzhandler($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function handlerElemPtr(Context $context, Value $idx): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal(self::GLOBAL_HANDLER);
        $ptr = $context->builder->pointerCast($global, $i32->pointerType(0));

        return $context->builder->inBoundsGEP($ptr, $idx);
    }

    private static function implementObGzhandlerBridge(Context $context): void
    {
        $abiName = '__compiler_ob_gzhandler';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ogz_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $accept = self::emitReadAcceptEncodingString($context, $fn);
        $encoding = self::resolveEncodingFromAccept($context, $accept);
        $result = $context->builder->call(
            self::handleHelper($context),
            $fn->getParam(0),
            $fn->getParam(1),
            $encoding
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGzhandlerFlushBridge(Context $context): void
    {
        $abiName = '__phpc_ob_gzhandler_flush';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ogf_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $accept = self::emitReadAcceptEncodingString($context, $fn);
        $encoding = self::resolveEncodingFromAccept($context, $accept);
        $result = $context->builder->call(
            self::flushHelper($context),
            $fn->getParam(0),
            $encoding
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function emitReadAcceptEncodingString(Context $context, LlvmFunction $fn): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $empty = self::emptyString($context);

        $serverGlobal = $context->module->getNamedGlobal('sg_SERVER');
        if (null === $serverGlobal) {
            return $empty;
        }
        $serverHt = $context->builder->load($serverGlobal);
        $noServer = $context->builder->icmp(Builder::INT_EQ, $serverHt, $htPtr->constNull());
        $doneBb = $fn->appendBasicBlock('ogz_ae_done_'.++self::$blockSuffix);
        $noServerBb = $fn->appendBasicBlock('ogz_ae_noserver_'.self::$blockSuffix);
        $readBb = $fn->appendBasicBlock('ogz_ae_read_'.self::$blockSuffix);
        $context->builder->branchIf($noServer, $noServerBb, $readBb);
        $context->builder->positionAtEnd($noServerBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($readBb);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(21, false),
            $context->builder->pointerCast($context->constantFromString('HTTP_ACCEPT_ENCODING'), $i8p)
        );
        $valPtrVar = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $serverHt,
            $keyStr
        );
        $noVal = $context->builder->icmp(Builder::INT_EQ, $valPtrVar, $valPtr->constNull());
        $noValBb = $fn->appendBasicBlock('ogz_ae_noval_'.self::$blockSuffix);
        $hasValBb = $fn->appendBasicBlock('ogz_ae_hasval_'.self::$blockSuffix);
        $context->builder->branchIf($noVal, $noValBb, $hasValBb);
        $context->builder->positionAtEnd($noValBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($hasValBb);
        $acceptStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtrVar);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr, 'ogz_accept');
        $phi->addIncoming($empty, $noServerBb);
        $phi->addIncoming($empty, $noValBb);
        $phi->addIncoming($acceptStr, $hasValBb);

        return $phi;
    }

    public static function implementObStartWithGzhandler(Context $context): void
    {
        $abiName = '__phpc_ob_start_with_gzhandler';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->context->voidType();
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('osg_standalone_entry');
        $skip = $fn->appendBasicBlock('osg_standalone_skip');
        $work = $fn->appendBasicBlock('osg_standalone_work');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $levelPtr = self::levelPtr($context);
        $level = $context->builder->load($levelPtr);
        $atMax = $context->builder->icmp(
            Builder::INT_SGE,
            $level,
            $i32->constInt(ObStackLimits::MAX_DEPTH, false)
        );
        $context->builder->branchIf($atMax, $skip, $work);
        $context->builder->positionAtEnd($work);
        $context->builder->store($context->getTypeFromString('int64')->constInt(0, false), self::lenElemPtr($context, $level));
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            self::storageRowPtr($context, $level)
        );
        $context->builder->store(
            $i32->constInt(ObGzhandlerJitRuntime::HANDLER_GZHANDLER, false),
            self::handlerElemPtr($context, $level)
        );
        $context->builder->store($context->builder->add($level, $i32->constInt(1, false)), $levelPtr);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function resolveEncodingFromAccept(Context $context, Value $accept): Value
    {
        ObGzhandlerJitRuntime::ensureJitHelperCompiledForLlvmBridge($context);

        return $context->builder->call(
            ObGzhandlerJitRuntime::resolveEncodingHelperFunction($context),
            $accept
        );
    }

    private static function handleHelper(Context $context): LlvmFunction
    {
        ObGzhandlerJitRuntime::ensureJitHelperCompiledForLlvmBridge($context);

        return ObGzhandlerJitRuntime::handleHelperFunction($context);
    }

    private static function flushHelper(Context $context): LlvmFunction
    {
        ObGzhandlerJitRuntime::ensureJitHelperCompiledForLlvmBridge($context);

        return ObGzhandlerJitRuntime::flushHelperFunction($context);
    }

    private static function emptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
    }

    private static function levelPtr(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_LEVEL);

        return $context->builder->pointerCast($global, $i32->pointerType(0));
    }

    private static function lenElemPtr(Context $context, Value $idx): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $global = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_LEN);
        $ptr = $context->builder->pointerCast($global, $i64->pointerType(0));

        return $context->builder->inBoundsGEP($ptr, $context->builder->sext($idx, $i64));
    }

    private static function storageRowPtr(Context $context, Value $idx): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $storage = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_STORAGE);
        $rowTy = $i8->arrayType(ObStackLimits::BUF_SIZE);
        $storageTy = $rowTy->arrayType(ObStackLimits::MAX_DEPTH);
        $base = $context->builder->pointerCast($storage, $storageTy->pointerType(0));
        $row = $context->builder->inBoundsGEP($base, $i64->constInt(0, false), $context->builder->sext($idx, $i64));

        return $context->builder->pointerCast($row, $i8->pointerType(0));
    }

    private static function ensureGlobals(Context $context): void
    {
        ObStorageGlobals::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $depth = ObStackLimits::MAX_DEPTH;
        $htPtr = $context->getTypeFromString('__hashtable__*');

        if (null === $context->module->getNamedGlobal(self::GLOBAL_HANDLER)) {
            $handlerTy = $i32->arrayType($depth);
            $handler = $context->module->addGlobal($handlerTy, self::GLOBAL_HANDLER);
            $handler->setInitializer($handlerTy->constNull());
        }

        if (null === $context->module->getNamedGlobal('sg_SERVER')) {
            $server = $context->module->addGlobal($htPtr, 'sg_SERVER');
            $server->setInitializer($htPtr->constNull());
        }
    }

    private static function ensureServerReadHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            '__hashtable__peekStringKeyValue',
            $context->context->functionType($valPtr, false, $htPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__value__readString',
            $context->context->functionType($strPtr, false, $valPtr)
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ObGzhandlerStandaloneLlvm (#9798)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
