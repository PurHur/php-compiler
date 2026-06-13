<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream_socket_pair() helper (php-src ext/standard/streams.c; #3437 phase 2).
 *
 * Creates a connected UNIX/INET socket pair and registers both ends in phpc_stream_handles.
 */
final class StreamSocketPairJit
{
    private const MAX_STREAM_HANDLES = 256;

    private const STREAM_HANDLE_BASE = 3;

    private const DEFAULT_CHUNK = 8192;

    private const GLOBAL_STREAM_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_STREAM_PATHS = 'phpc_stream_paths';

    private const GLOBAL_STREAM_CHUNK = 'phpc_stream_chunk_size';

    private const GLOBAL_STREAM_WBUF = 'phpc_stream_write_buffer';

    private const GLOBAL_STREAM_RBUF = 'phpc_stream_read_buffer';

    private const GLOBAL_STREAM_WAS_USED = 'phpc_stream_was_used';

    private const AF_UNIX = 1;

    private const AF_INET = 2;

    private const SOCK_STREAM = 1;

    private const SOCK_DGRAM = 2;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_socket_pair',
    ];

    private static int $blockSuffix = 0;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::$blockSuffix = 0;
        StreamIoJit::ensureStreamGlobals($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing(
            $context,
            '__compiler_stream_socket_pair',
            self::shouldDeferInventoryEmit($context) ? self::emitStreamSocketPairStub(...) : self::emitStreamSocketPair(...)
        );
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($htPtr, false, $i64, $i64, $i64)
        );
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function shouldDeferInventoryEmit(Context $context): bool
    {
        unset($context);
        foreach (['PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', 'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER'] as $key) {
            $flag = getenv($key);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }

        return false;
    }

    private static function emitStreamSocketPairStub(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ssp_stub_entry');
        $context->builder->positionAtEnd($entry);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $context->builder->returnValue($htPtr->constNull());
    }

    private static function emitStreamSocketPair(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ssp_entry');
        $context->builder->positionAtEnd($entry);

        $domain = $fn->getParam(0);
        $type = $fn->getParam(1);
        $protocol = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI32 = $i32->constInt(0, false);

        $failBb = $fn->appendBasicBlock('ssp_fail');

        $af = self::mapDomain($context, $fn, $domain, $failBb);
        $sockType = self::mapType($context, $fn, $type, $failBb);
        self::guardProtocolTriple($context, $fn, $af, $sockType, $protocol, $failBb);

        $pairSlot = $context->builder->alloca($i32, 2, 'ssp_pair');
        $pairRc = $context->builder->call(
            $context->lookupFunction('socketpair'),
            $context->builder->trunc($af, $i32),
            $context->builder->trunc($sockType, $i32),
            $context->builder->trunc($protocol, $i32),
            $pairSlot
        );
        $pairFail = $context->builder->icmp(Builder::INT_NE, $pairRc, $zeroI32);
        $openBb = $fn->appendBasicBlock('ssp_open');
        $context->builder->branchIf($pairFail, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fd0 = $context->builder->load($context->builder->gep($pairSlot, $i32->constInt(0, false)));
        $fd1 = $context->builder->load($context->builder->gep($pairSlot, $i32->constInt(1, false)));

        $fp0 = self::fdopenDup($context, $fn, $fd0, $failBb);
        $fp1 = self::fdopenDup($context, $fn, $fd1, $failBb);

        $handle0 = self::registerStreamFp($context, $fn, $fp0, $failBb);
        $handle1 = self::registerStreamFp($context, $fn, $fp1, $failBb);

        $regFail = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $handle0, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SLT, $handle1, $i64->constInt(0, false))
        );
        $buildBb = $fn->appendBasicBlock('ssp_build');
        $context->builder->branchIf($regFail, $failBb, $buildBb);

        $context->builder->positionAtEnd($buildBb);
        $result = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $resultNull = $context->builder->icmp(Builder::INT_EQ, $result, $nullHt);
        $okBb = $fn->appendBasicBlock('ssp_ok');
        $context->builder->branchIf($resultNull, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call($setLong, $result, $sizeT->constInt(0, false), $handle0);
        $context->builder->call($setLong, $result, $sizeT->constInt(1, false), $handle1);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullHt);
    }

    private static function mapDomain(Context $context, LlvmFunction $fn, Value $domain, BasicBlock $failBb): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $unixBb = $fn->appendBasicBlock('ssp_domain_unix');
        $inetBb = $fn->appendBasicBlock('ssp_domain_inet');
        $unixDoneBb = $fn->appendBasicBlock('ssp_domain_unix_done');
        $mergeBb = $fn->appendBasicBlock('ssp_domain_done');
        $resultSlot = $context->builder->alloca($i64, 1, 'ssp_af');

        $isUnix = $context->builder->icmp(
            Builder::INT_EQ,
            $domain,
            $i64->constInt(StdlibConstants::STREAM_PF_UNIX, false)
        );
        $isInet = $context->builder->icmp(
            Builder::INT_EQ,
            $domain,
            $i64->constInt(StdlibConstants::STREAM_PF_INET, false)
        );
        $known = $context->builder->or($isUnix, $isInet);
        $context->builder->branchIf($known, $unixBb, $failBb);

        $context->builder->positionAtEnd($unixBb);
        $context->builder->branchIf($isUnix, $unixDoneBb, $inetBb);

        $context->builder->positionAtEnd($unixDoneBb);
        $context->builder->store($i64->constInt(self::AF_UNIX, false), $resultSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($inetBb);
        $context->builder->store($i64->constInt(self::AF_INET, false), $resultSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $context->builder->load($resultSlot);
    }

    private static function mapType(Context $context, LlvmFunction $fn, Value $type, BasicBlock $failBb): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $streamBb = $fn->appendBasicBlock('ssp_type_stream');
        $dgramBb = $fn->appendBasicBlock('ssp_type_dgram');
        $streamDoneBb = $fn->appendBasicBlock('ssp_type_stream_done');
        $mergeBb = $fn->appendBasicBlock('ssp_type_done');
        $resultSlot = $context->builder->alloca($i64, 1, 'ssp_sock_type');

        $isStream = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i64->constInt(StdlibConstants::STREAM_SOCK_STREAM, false)
        );
        $isDgram = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i64->constInt(StdlibConstants::STREAM_SOCK_DGRAM, false)
        );
        $known = $context->builder->or($isStream, $isDgram);
        $context->builder->branchIf($known, $streamBb, $failBb);

        $context->builder->positionAtEnd($streamBb);
        $context->builder->branchIf($isStream, $streamDoneBb, $dgramBb);

        $context->builder->positionAtEnd($streamDoneBb);
        $context->builder->store($i64->constInt(self::SOCK_STREAM, false), $resultSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($dgramBb);
        $context->builder->store($i64->constInt(self::SOCK_DGRAM, false), $resultSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $context->builder->load($resultSlot);
    }

    private static function guardProtocolTriple(
        Context $context,
        LlvmFunction $fn,
        Value $af,
        Value $sockType,
        Value $protocol,
        BasicBlock $failBb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $isUnix = $context->builder->icmp(Builder::INT_EQ, $af, $i64->constInt(self::AF_UNIX, false));
        $okBb = $fn->appendBasicBlock('ssp_proto_ok');
        $checkInetBb = $fn->appendBasicBlock('ssp_proto_inet');
        $context->builder->branchIf($isUnix, $okBb, $checkInetBb);

        $context->builder->positionAtEnd($checkInetBb);
        $isInet = $context->builder->icmp(Builder::INT_EQ, $af, $i64->constInt(self::AF_INET, false));
        $inetFailBb = $fn->appendBasicBlock('ssp_proto_inet_fail');
        $inetCheckBb = $fn->appendBasicBlock('ssp_proto_inet_check');
        $context->builder->branchIf($isInet, $inetCheckBb, $inetFailBb);

        $context->builder->positionAtEnd($inetCheckBb);
        $isStream = $context->builder->icmp(Builder::INT_EQ, $sockType, $i64->constInt(self::SOCK_STREAM, false));
        $protoOk = $context->builder->icmp(
            Builder::INT_EQ,
            $protocol,
            $i64->constInt(StdlibConstants::STREAM_IPPROTO_IP, false)
        );
        $inetOk = $context->builder->and($isStream, $protoOk);
        $context->builder->branchIf($inetOk, $okBb, $failBb);

        $context->builder->positionAtEnd($inetFailBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okBb);
    }

    private static function fdopenDup(Context $context, LlvmFunction $fn, Value $fd, BasicBlock $failBb): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $suffix = (string) ++self::$blockSuffix;

        $dupFd = $context->builder->call($context->lookupFunction('dup'), $fd);
        $dupFail = $context->builder->icmp(Builder::INT_SLT, $dupFd, $i32->constInt(0, false));
        $openBb = $fn->appendBasicBlock('ssp_fdopen_'.$suffix);
        $context->builder->branchIf($dupFail, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            $dupFd,
            self::literalCstr($context, 'r+')
        );
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $closeDupBb = $fn->appendBasicBlock('ssp_fdopen_close_dup_'.$suffix);
        $okBb = $fn->appendBasicBlock('ssp_fdopen_ok_'.$suffix);
        $context->builder->branchIf($fpNull, $closeDupBb, $okBb);

        $context->builder->positionAtEnd($closeDupBb);
        $context->builder->call($context->lookupFunction('close'), $dupFd);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call($context->lookupFunction('close'), $fd);

        return $fp;
    }

    private static function registerStreamFp(
        Context $context,
        LlvmFunction $fn,
        Value $fp,
        BasicBlock $failBb
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $minusOne = $i64->constInt(-1, true);
        $defaultChunk = $i32->constInt(self::DEFAULT_CHUNK, false);
        $suffix = (string) ++self::$blockSuffix;

        $resultSlot = $context->builder->alloca($i64, 1, 'ssp_reg_result_'.$suffix);
        $idSlot = $context->builder->alloca($i64, 1, 'ssp_reg_id_'.$suffix);
        $doneBb = $fn->appendBasicBlock('ssp_reg_done_'.$suffix);

        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $storeFail = $fn->appendBasicBlock('ssp_reg_null_'.$suffix);
        $loopInit = $fn->appendBasicBlock('ssp_reg_init_'.$suffix);
        $context->builder->branchIf($fpNull, $storeFail, $loopInit);

        $context->builder->positionAtEnd($storeFail);
        $context->builder->store($minusOne, $resultSlot);
        $context->builder->branch($doneBb);

        $loopCheck = $fn->appendBasicBlock('ssp_reg_check_'.$suffix);
        $loopBody = $fn->appendBasicBlock('ssp_reg_body_'.$suffix);
        $loopSkip = $fn->appendBasicBlock('ssp_reg_skip_'.$suffix);
        $loopInc = $fn->appendBasicBlock('ssp_reg_inc_'.$suffix);
        $exhaust = $fn->appendBasicBlock('ssp_reg_exhaust_'.$suffix);

        $context->builder->positionAtEnd($loopInit);
        $context->builder->store($i64->constInt(self::STREAM_HANDLE_BASE, false), $idSlot);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($loopCheck);
        $idPhi = $context->builder->load($idSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $i64->constInt(self::MAX_STREAM_HANDLES, false));
        $context->builder->branchIf($atEnd, $exhaust, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $slotFp = self::loadStreamSlot($context, $idPhi);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotFp, $nullPtr);
        $allocBb = $fn->appendBasicBlock('ssp_reg_alloc_'.$suffix);
        $context->builder->branchIf($slotFree, $allocBb, $loopSkip);

        $context->builder->positionAtEnd($allocBb);
        self::storeStreamSlot($context, self::GLOBAL_STREAM_HANDLES, $idPhi, $fp);
        self::storeStreamI32Slot($context, self::GLOBAL_STREAM_CHUNK, $idPhi, $defaultChunk);
        self::storeStreamI32Slot($context, self::GLOBAL_STREAM_WBUF, $idPhi, $defaultChunk);
        self::storeStreamI32Slot($context, self::GLOBAL_STREAM_RBUF, $idPhi, $defaultChunk);
        self::storeStreamSlot($context, self::GLOBAL_STREAM_PATHS, $idPhi, $nullPtr);
        self::storeStreamI8Flag($context, self::GLOBAL_STREAM_WAS_USED, $idPhi);
        $context->builder->store($idPhi, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loopSkip);
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $context->builder->store($nextId, $idSlot);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($exhaust);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->store($minusOne, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    private static function loadStreamSlot(Context $context, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_STREAM_HANDLES);
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storeStreamSlot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storeStreamI32Slot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storeStreamI8Flag(Context $context, string $globalName, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($i8->constInt(1, false), $context->builder->bitcast($slot, $i8->pointerType(0)));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i32p = $i32->pointerType(0);

        foreach ([
            ['socketpair', $i32, [$i32, $i32, $i32, $i32p]],
            ['dup', $i32, [$i32]],
            ['close', $i32, [$i32]],
            ['fdopen', $i8p, [$i32, $i8p]],
            ['fclose', $i32, [$i8p]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setLongAt', $voidTy, [$htPtr, $sizeT, $i64]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
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

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamSocketPairJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
