<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ObStackLimits;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for ob_gzhandler() gzip output-buffer handler (issue #4655, #8818).
 *
 * Mirrors {@see \PHPCompiler\ext\standard\VmObGzhandler}; gzip via {@see StringZlibJit}.
 * php-src: ext/zlib/zlib.c — php_ob_gzhandler
 */
final class ObGzhandlerJitRuntime
{
    public const HANDLER_NONE = 0;

    public const HANDLER_GZHANDLER = 1;

    private const GLOBAL_ENCODING = '__phpc_ob_gz_encoding';

    private const GLOBAL_HANDLER = '__phpc_ob_handler';

    private const ZLIB_ENCODING_GZIP = 31;

    private const ZLIB_ENCODING_DEFLATE = 65535;

    private const PHP_OUTPUT_HANDLER_START = 1;

    private const PHP_OUTPUT_HANDLER_END = 8;

    private const PHP_OUTPUT_HANDLER_FINAL = 4;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_ob_gzhandler',
        '__phpc_ob_gzhandler_flush',
        '__phpc_ob_start_with_gzhandler',
    ];

    private static int $blockSuffix = 0;

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ob_gzhandler');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);
        self::ensureStringHelpers($context);
        StringZlib::ensureLinked($context);

        self::implementObGzhandler($context);
        self::implementGzhandlerFlush($context);
        self::implementObStartWithGzhandler($context);

        self::registerLinkedRuntime($context);
    }

    public static function handlerElemPtr(Context $context, Value $idx): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal(self::GLOBAL_HANDLER);
        $ptr = $context->builder->pointerCast($global, $i32->pointerType(0));

        return $context->builder->inBoundsGEP($ptr, $idx);
    }

    public static function isGzhandlerAt(Context $context, Value $idx): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $kind = $context->builder->load(self::handlerElemPtr($context, $idx));

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i32->constInt(self::HANDLER_GZHANDLER, false)
        );
    }

    public static function emitApplyGzhandlerToString(Context $context, Value $content): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__phpc_ob_gzhandler_flush'),
            $content
        );
    }

    private static function implementObGzhandler(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = self::fn($context, '__compiler_ob_gzhandler', $strPtr, false, $strPtr, $i64);
        $entry = $fn->appendBasicBlock('ogz_entry');
        $context->builder->positionAtEnd($entry);
        self::emitHandleBody($context, $fn, $fn->getParam(0), $fn->getParam(1));
        $context->builder->clearInsertionPosition();
    }

    private static function implementGzhandlerFlush(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = self::fn($context, '__phpc_ob_gzhandler_flush', $strPtr, false, $strPtr);
        $entry = $fn->appendBasicBlock('ogf_entry');
        $context->builder->positionAtEnd($entry);
        $content = $fn->getParam(0);
        $empty = self::emptyString($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_ob_gzhandler'),
            $empty,
            $i64->constInt(self::PHP_OUTPUT_HANDLER_START, false)
        );
        $processed = $context->builder->call(
            $context->lookupFunction('__compiler_ob_gzhandler'),
            $content,
            $i64->constInt(self::PHP_OUTPUT_HANDLER_END, false)
        );
        $hasProcessed = $context->builder->icmp(Builder::INT_NE, $processed, $empty);
        $useProcessed = $fn->appendBasicBlock('ogf_use_'.++self::$blockSuffix);
        $useRaw = $fn->appendBasicBlock('ogf_raw_'.self::$blockSuffix);
        $context->builder->branchIf($hasProcessed, $useProcessed, $useRaw);
        $context->builder->positionAtEnd($useProcessed);
        $context->builder->returnValue($processed);
        $context->builder->positionAtEnd($useRaw);
        $context->builder->returnValue($content);
        $context->builder->clearInsertionPosition();
    }

    private static function implementObStartWithGzhandler(Context $context): void
    {
        $fn = self::fn($context, '__phpc_ob_start_with_gzhandler', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('osg_entry');
        $skip = $fn->appendBasicBlock('osg_skip');
        $work = $fn->appendBasicBlock('osg_work');
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
            $i32->constInt(self::HANDLER_GZHANDLER, false),
            self::handlerElemPtr($context, $level)
        );
        $context->builder->store($context->builder->add($level, $i32->constInt(1, false)), $levelPtr);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitHandleBody(Context $context, LlvmFunction $fn, Value $data, Value $mode): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $encoding = self::emitResolveEncoding($context, $fn);
        $noEnc = $fn->appendBasicBlock('ogz_noenc_'.++self::$blockSuffix);
        $hasEnc = $fn->appendBasicBlock('ogz_hasenc_'.self::$blockSuffix);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $encoding, $i64->constInt(0, false));
        $context->builder->branchIf($isZero, $noEnc, $hasEnc);
        $context->builder->positionAtEnd($noEnc);
        self::emitPassthroughBody($context, $fn, $data, $mode);
        $context->builder->positionAtEnd($hasEnc);
        $startBit = $context->builder->and($mode, $i64->constInt(self::PHP_OUTPUT_HANDLER_START, false));
        $isStart = $context->builder->icmp(Builder::INT_NE, $startBit, $i64->constInt(0, false));
        $endBit = $context->builder->or(
            $context->builder->and($mode, $i64->constInt(self::PHP_OUTPUT_HANDLER_END, false)),
            $context->builder->and($mode, $i64->constInt(self::PHP_OUTPUT_HANDLER_FINAL, false))
        );
        $isEnd = $context->builder->icmp(Builder::INT_NE, $endBit, $i64->constInt(0, false));
        $startBb = $fn->appendBasicBlock('ogz_start_'.self::$blockSuffix);
        $endBb = $fn->appendBasicBlock('ogz_end_'.self::$blockSuffix);
        $contBb = $fn->appendBasicBlock('ogz_cont_'.self::$blockSuffix);
        $context->builder->branchIf($isStart, $startBb, $endBb);
        $context->builder->positionAtEnd($startBb);
        $context->builder->returnValue(self::emptyString($context));
        $context->builder->positionAtEnd($endBb);
        $endWork = $fn->appendBasicBlock('ogz_endwork_'.self::$blockSuffix);
        $context->builder->branchIf($isEnd, $endWork, $contBb);
        $context->builder->positionAtEnd($endWork);
        $emptyData = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $endEmpty = $fn->appendBasicBlock('ogz_endempty_'.self::$blockSuffix);
        $endGzip = $fn->appendBasicBlock('ogz_endgzip_'.self::$blockSuffix);
        $context->builder->branchIf($emptyData, $endEmpty, $endGzip);
        $context->builder->positionAtEnd($endEmpty);
        $context->builder->returnValue(self::emptyString($context));
        $context->builder->positionAtEnd($endGzip);
        $compressed = $context->builder->call(
            $context->lookupFunction('__compiler_gzencode'),
            $data,
            $i64->constInt(-1, true),
            $encoding
        );
        $gzipFail = $context->builder->icmp(Builder::INT_EQ, $compressed, $strPtr->constNull());
        $failBb = $fn->appendBasicBlock('ogz_gzfail_'.self::$blockSuffix);
        $okBb = $fn->appendBasicBlock('ogz_gzok_'.self::$blockSuffix);
        $context->builder->branchIf($gzipFail, $failBb, $okBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($data);
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($compressed);
        $context->builder->positionAtEnd($contBb);
        $context->builder->returnValue(self::emptyString($context));
    }

    private static function emitPassthroughBody(Context $context, LlvmFunction $fn, Value $data, Value $mode): void
    {
        $i64 = $context->getTypeFromString('int64');
        $startBit = $context->builder->and($mode, $i64->constInt(self::PHP_OUTPUT_HANDLER_START, false));
        $isStart = $context->builder->icmp(Builder::INT_NE, $startBit, $i64->constInt(0, false));
        $endBit = $context->builder->or(
            $context->builder->and($mode, $i64->constInt(self::PHP_OUTPUT_HANDLER_END, false)),
            $context->builder->and($mode, $i64->constInt(self::PHP_OUTPUT_HANDLER_FINAL, false))
        );
        $isEnd = $context->builder->icmp(Builder::INT_NE, $endBit, $i64->constInt(0, false));
        $startBb = $fn->appendBasicBlock('ogz_ptstart_'.++self::$blockSuffix);
        $endBb = $fn->appendBasicBlock('ogz_ptend_'.self::$blockSuffix);
        $contBb = $fn->appendBasicBlock('ogz_ptcont_'.self::$blockSuffix);
        $context->builder->branchIf($isStart, $startBb, $endBb);
        $context->builder->positionAtEnd($startBb);
        $context->builder->returnValue(self::emptyString($context));
        $context->builder->positionAtEnd($endBb);
        $endRet = $fn->appendBasicBlock('ogz_ptendret_'.self::$blockSuffix);
        $context->builder->branchIf($isEnd, $endRet, $contBb);
        $context->builder->positionAtEnd($endRet);
        $context->builder->returnValue($data);
        $context->builder->positionAtEnd($contBb);
        $context->builder->returnValue(self::emptyString($context));
    }

    private static function emitResolveEncoding(Context $context, LlvmFunction $fn): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $encGlobal = $context->module->getNamedGlobal(self::GLOBAL_ENCODING);
        $encPtr = $context->builder->pointerCast($encGlobal, $i64->pointerType(0));
        $cached = $context->builder->load($encPtr);
        $cachedBb = $fn->appendBasicBlock('ogz_cached_'.++self::$blockSuffix);
        $resolveBb = $fn->appendBasicBlock('ogz_resolve_'.self::$blockSuffix);
        $doneBb = $fn->appendBasicBlock('ogz_encdone_'.self::$blockSuffix);
        $hasCached = $context->builder->icmp(Builder::INT_NE, $cached, $i64->constInt(0, false));
        $context->builder->branchIf($hasCached, $cachedBb, $resolveBb);
        $context->builder->positionAtEnd($cachedBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($resolveBb);
        $resolved = self::emitReadAcceptEncoding($context, $fn);
        $context->builder->store($resolved, $encPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i64, 'ogz_enc');
        $phi->addIncoming($cached, $cachedBb);
        $phi->addIncoming($resolved, $resolveBb);

        return $phi;
    }

    private static function emitReadAcceptEncoding(Context $context, LlvmFunction $fn): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zero = $i64->constInt(0, false);
        $serverGlobal = $context->module->getNamedGlobal('sg_SERVER');
        if (null === $serverGlobal) {
            return $zero;
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
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $serverHt,
            $keyStr
        );
        $valPtrTy = $context->getTypeFromString('__value__*');
        $noVal = $context->builder->icmp(Builder::INT_EQ, $valPtr, $valPtrTy->constNull());
        $noValBb = $fn->appendBasicBlock('ogz_ae_noval_'.self::$blockSuffix);
        $hasValBb = $fn->appendBasicBlock('ogz_ae_hasval_'.self::$blockSuffix);
        $context->builder->branchIf($noVal, $noValBb, $hasValBb);
        $context->builder->positionAtEnd($noValBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($hasValBb);
        $acceptStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $gzipBb = $fn->appendBasicBlock('ogz_ae_gzip_'.self::$blockSuffix);
        $deflateBb = $fn->appendBasicBlock('ogz_ae_deflate_'.self::$blockSuffix);
        $noneBb = $fn->appendBasicBlock('ogz_ae_none_'.self::$blockSuffix);
        $hasGzip = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call(
                $context->lookupFunction('strstr'),
                $context->builder->pointerCast($acceptStr, $i8p),
                $context->builder->pointerCast($context->constantFromString('gzip'), $i8p)
            ),
            $i8p->constNull()
        );
        $context->builder->branchIf($hasGzip, $gzipBb, $deflateBb);
        $context->builder->positionAtEnd($gzipBb);
        $gzipEnc = $i64->constInt(self::ZLIB_ENCODING_GZIP, false);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($deflateBb);
        $hasDeflate = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call(
                $context->lookupFunction('strstr'),
                $context->builder->pointerCast($acceptStr, $i8p),
                $context->builder->pointerCast($context->constantFromString('deflate'), $i8p)
            ),
            $i8p->constNull()
        );
        $deflateRet = $fn->appendBasicBlock('ogz_ae_deflateret_'.self::$blockSuffix);
        $context->builder->branchIf($hasDeflate, $deflateRet, $noneBb);
        $context->builder->positionAtEnd($deflateRet);
        $deflateEnc = $i64->constInt(self::ZLIB_ENCODING_DEFLATE, false);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($noneBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i64, 'ogz_ae');
        $phi->addIncoming($zero, $noServerBb);
        $phi->addIncoming($zero, $noValBb);
        $phi->addIncoming($gzipEnc, $gzipBb);
        $phi->addIncoming($deflateEnc, $deflateRet);
        $phi->addIncoming($zero, $noneBb);

        return $phi;
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
        $i64 = $context->getTypeFromString('int64');
        $depth = ObStackLimits::MAX_DEPTH;

        if (null === $context->module->getNamedGlobal(self::GLOBAL_ENCODING)) {
            $enc = $context->module->addGlobal($i64, self::GLOBAL_ENCODING);
            $enc->setInitializer($i64->constInt(0, false));
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_HANDLER)) {
            $handlerTy = $i32->arrayType($depth);
            $handler = $context->module->addGlobal($handlerTy, self::GLOBAL_HANDLER);
            $handler->setInitializer($handlerTy->constNull());
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        if (null === $context->module->getNamedGlobal('sg_SERVER')) {
            $server = $context->module->addGlobal($htPtr, 'sg_SERVER');
            $server->setInitializer($htPtr->constNull());
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        self::ensureExternal(
            $context,
            'strstr',
            $context->context->functionType($i8p, false, $i8p, $i8p)
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
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
    }

    private static function ensureStringHelpers(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
    }

    private static function fn(Context $context, string $name, $ret, bool $vararg, ...$params): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            return $probe;
        }
        $ft = $context->context->functionType($ret, $vararg, ...$params);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
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
                throw new \LogicException($name.' missing after ObGzhandlerJitRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
