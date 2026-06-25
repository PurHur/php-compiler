<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM pending HTTP header queue (#5344, #9545).
 *
 * Embed routes through {@see PendingHeadersJitBridge} + {@see \PHPCompiler\ext\standard\PendingHeadersJitHelper}.
 * Replaces lib/AOT/runtime/phpc_pending_headers.c. php-src: ext/standard/head.c
 */
final class PendingHeadersStandaloneLlvm
{
    private const MAX_HEADERS = 256;

    private const G_COUNT = 'phpc_pending_header_count';

    private const G_FLUSHED = 'phpc_pending_headers_flushed';

    private const G_LINES = 'phpc_pending_header_lines';

    private const G_SAPI_STARTED = '__phpc_sapi_output_started';

    private const G_QUEUE_ENABLED = 'phpc_header_queue_enabled';

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::$blockSuffix = 0;
        $probe = $context->module->getNamedFunction('__phpc_pending_header_reset');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);

        self::implementReset($context);
        self::implementEnableHeaderQueue($context);
        self::implementHeadersSent($context);
        self::implementRemove($context);
        self::implementAdd($context);
        self::implementList($context);
        self::implementFlush($context);
        self::implementSetcookieAdd($context);
        self::registerLinkedRuntime($context);
    }

    private static function implementReset(Context $context): void
    {
        $fn = self::fn($context, '__phpc_pending_header_reset', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('ph_reset_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $context->builder->store($i32->constInt(0, false), self::countPtr($context));
        $context->builder->store($i32->constInt(0, false), self::flushedPtr($context));
        $context->builder->store($i32->constInt(0, false), self::queueEnabledPtr($context));
        $sapi = $context->module->getNamedGlobal(self::G_SAPI_STARTED);
        if (null !== $sapi) {
            $context->builder->store($i32->constInt(0, false), self::globalPtr($context, self::G_SAPI_STARTED, $i32));
        }
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementEnableHeaderQueue(Context $context): void
    {
        $fn = self::fn($context, '__phpc_header_queue_enable', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('ph_queue_enable_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store($i32->constInt(1, false), self::queueEnabledPtr($context));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementHeadersSent(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $fn = self::fn($context, '__phpc_headers_sent', $i32, false);
        $entry = $fn->appendBasicBlock('ph_sent_entry');
        $context->builder->positionAtEnd($entry);

        $flushed = $context->builder->load(self::flushedPtr($context));
        $sapiStarted = $i32->constInt(0, false);
        $sapi = $context->module->getNamedGlobal(self::G_SAPI_STARTED);
        if (null !== $sapi) {
            $sapiStarted = $context->builder->load(self::globalPtr($context, self::G_SAPI_STARTED, $i32));
        }
        $sent = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $flushed, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $sapiStarted, $i32->constInt(0, false))
        );
        $context->builder->returnValue($context->builder->zExt($sent, $i32));
        $context->builder->clearInsertionPosition();
    }

    private static function implementRemove(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = self::fn($context, '__phpc_pending_header_remove', $context->context->voidType(), false, $strPtr);
        $entry = $fn->appendBasicBlock('ph_rem_entry');
        $disabled = $fn->appendBasicBlock('ph_rem_disabled');
        $afterGate = $fn->appendBasicBlock('ph_rem_after_gate');
        $emptyName = $fn->appendBasicBlock('ph_rem_clear');
        $loopInit = $fn->appendBasicBlock('ph_rem_loop_init');
        $loopHead = $fn->appendBasicBlock('ph_rem_loop_head');
        $loopMatch = $fn->appendBasicBlock('ph_rem_match');
        $loopKeep = $fn->appendBasicBlock('ph_rem_keep');
        $loopInc = $fn->appendBasicBlock('ph_rem_inc');
        $loopDone = $fn->appendBasicBlock('ph_rem_done');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $queueOn = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load(self::queueEnabledPtr($context)),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($queueOn, $afterGate, $disabled);
        $context->builder->positionAtEnd($disabled);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($afterGate);
        $name = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $name, $strPtr->constNull());
        $nameLen = self::stringLen($context, $name);
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $nameLen, $i64->constInt(0, false));
        $clearAll = $context->builder->or($isNull, $zeroLen);
        $context->builder->branchIf($clearAll, $emptyName, $loopInit);

        $context->builder->positionAtEnd($emptyName);
        $context->builder->store($i32->constInt(0, false), self::countPtr($context));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($loopInit);
        $writeIdx = $context->builder->alloca($i32, 1);
        $readIdx = $context->builder->alloca($i32, 1);
        $context->builder->store($i32->constInt(0, false), $writeIdx);
        $context->builder->store($i32->constInt(0, false), $readIdx);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $count = $context->builder->load(self::countPtr($context));
        $ri = $context->builder->load($readIdx);
        $more = $context->builder->icmp(Builder::INT_SLT, $ri, $count);
        $context->builder->branchIf($more, $loopMatch, $loopDone);

        $context->builder->positionAtEnd($loopMatch);
        $line = self::loadLineAt($context, $ri);
        $matches = self::headerNameMatches($context, $name, $line);
        $context->builder->branchIf($matches, $loopInc, $loopKeep);

        $context->builder->positionAtEnd($loopKeep);
        $wi = $context->builder->load($writeIdx);
        $sameSlot = $context->builder->icmp(Builder::INT_EQ, $wi, $ri);
        $copyBb = $fn->appendBasicBlock('ph_rem_copy_'.++self::$blockSuffix);
        $skipCopyBb = $fn->appendBasicBlock('ph_rem_skip_copy_'.self::$blockSuffix);
        $context->builder->branchIf($sameSlot, $skipCopyBb, $copyBb);
        $context->builder->positionAtEnd($copyBb);
        self::storeLineAt($context, $wi, $line);
        $context->builder->branch($skipCopyBb);
        $context->builder->positionAtEnd($skipCopyBb);
        $context->builder->store(
            $context->builder->add($wi, $i32->constInt(1, false)),
            $writeIdx
        );
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($context->builder->load($readIdx), $i32->constInt(1, false)),
            $readIdx
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->store($context->builder->load($writeIdx), self::countPtr($context));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementAdd(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = self::fn($context, '__phpc_pending_header_add', $context->context->voidType(), false, $strPtr, $i32);
        $entry = $fn->appendBasicBlock('ph_add_entry');
        $skip = $fn->appendBasicBlock('ph_add_skip');
        $queueGate = $fn->appendBasicBlock('ph_add_queue_gate');
        $statusOnly = $fn->appendBasicBlock('ph_add_status_only');
        $work = $fn->appendBasicBlock('ph_add_work');
        $context->builder->positionAtEnd($entry);

        $line = $fn->getParam(0);
        $replace = $fn->getParam(1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $hasCrlf = self::lineHasCrlf($context, $fn, $line);
        $bad = $context->builder->or($isNull, $hasCrlf);
        $context->builder->branchIf($bad, $skip, $queueGate);

        $context->builder->positionAtEnd($queueGate);
        $queueOn = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load(self::queueEnabledPtr($context)),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($queueOn, $work, $statusOnly);

        $context->builder->positionAtEnd($statusOnly);
        self::maybeSetLocationStatus($context, $line);
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($work);
        self::maybeSetLocationStatus($context, $line);
        $doReplace = $context->builder->icmp(Builder::INT_NE, $replace, $i32->constInt(0, false));
        $afterReplace = $fn->appendBasicBlock('ph_add_after_replace');
        $noReplace = $fn->appendBasicBlock('ph_add_no_replace');
        $append = $fn->appendBasicBlock('ph_add_append');
        $context->builder->branchIf($doReplace, $afterReplace, $noReplace);

        $context->builder->positionAtEnd($afterReplace);
        $nameLine = self::extractHeaderNameString($context, $line);
        $hasName = $context->builder->icmp(Builder::INT_NE, $nameLine, $strPtr->constNull());
        $removeBb = $fn->appendBasicBlock('ph_add_remove');
        $context->builder->branchIf($hasName, $removeBb, $noReplace);
        $context->builder->positionAtEnd($removeBb);
        $context->builder->call($context->lookupFunction('__phpc_pending_header_remove'), $nameLine);
        $context->builder->branch($append);

        $context->builder->positionAtEnd($noReplace);
        $context->builder->branch($append);

        $context->builder->positionAtEnd($append);
        $count = $context->builder->load(self::countPtr($context));
        $atMax = $context->builder->icmp(Builder::INT_SGE, $count, $i32->constInt(self::MAX_HEADERS, false));
        $appendOk = $fn->appendBasicBlock('ph_add_store');
        $context->builder->branchIf($atMax, $skip, $appendOk);
        $context->builder->positionAtEnd($appendOk);
        self::storeLineAt($context, $count, $line);
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            self::countPtr($context)
        );
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementList(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = self::fn($context, '__phpc_pending_header_list', $htPtr, false);
        $entry = $fn->appendBasicBlock('ph_list_entry');
        $loopInit = $fn->appendBasicBlock('ph_list_init');
        $loopHead = $fn->appendBasicBlock('ph_list_loop');
        $loopBody = $fn->appendBasicBlock('ph_list_body');
        $done = $fn->appendBasicBlock('ph_list_done');
        $skipList = $fn->appendBasicBlock('ph_list_skip');
        $checkEmpty = $fn->appendBasicBlock('ph_list_check_empty');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $gateway = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'GATEWAY_INTERFACE')
        );
        $gatewayMissing = $context->builder->icmp(Builder::INT_EQ, $gateway, $i8p->constNull());
        $context->builder->branchIf($gatewayMissing, $skipList, $checkEmpty);

        $context->builder->positionAtEnd($checkEmpty);
        $gatewayEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($gateway),
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($gatewayEmpty, $skipList, $loopInit);

        $context->builder->positionAtEnd($skipList);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($loopInit);
        $idxSlot = $context->builder->alloca($i32, 1);
        $context->builder->store($i32->constInt(0, false), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $count = $context->builder->load(self::countPtr($context));
        $idx = $context->builder->load($idxSlot);
        $more = $context->builder->icmp(Builder::INT_SLT, $idx, $count);
        $context->builder->branchIf($more, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $line = self::loadLineAt($context, $idx);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $context->builder->zExt($idx, $i64),
            $line
        );
        $context->builder->store(
            $context->builder->add($idx, $i32->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFlush(Context $context): void
    {
        $fn = self::fn($context, '__phpc_response_headers_flush', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('ph_flush_entry');
        $already = $fn->appendBasicBlock('ph_flush_already');
        $skipEmit = $fn->appendBasicBlock('ph_flush_skip_emit');
        $work = $fn->appendBasicBlock('ph_flush_work');
        $statusBb = $fn->appendBasicBlock('ph_flush_status');
        $skipStatus = $fn->appendBasicBlock('ph_flush_skip_status');
        $loopHead = $fn->appendBasicBlock('ph_flush_loop');
        $loopBody = $fn->appendBasicBlock('ph_flush_body');
        $trail = $fn->appendBasicBlock('ph_flush_trail');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $flushed = $context->builder->load(self::flushedPtr($context));
        $wasFlushed = $context->builder->icmp(Builder::INT_NE, $flushed, $i32->constInt(0, false));
        $envGate = $fn->appendBasicBlock('ph_flush_env_gate');
        $context->builder->branchIf($wasFlushed, $already, $envGate);

        $context->builder->positionAtEnd($already);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($envGate);
        self::branchIfWebResponseEnvPresent($context, $fn, $work, $skipEmit);

        $context->builder->positionAtEnd($skipEmit);
        $context->builder->store($i32->constInt(1, false), self::flushedPtr($context));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $context->builder->store($i32->constInt(1, false), self::flushedPtr($context));
        $wroteSlot = $context->builder->alloca($i32, 1);
        $context->builder->store($i32->constInt(0, false), $wroteSlot);
        $idxSlot = $context->builder->alloca($i32, 1);
        $context->builder->store($i32->constInt(0, false), $idxSlot);

        $status = HttpResponseRuntime::loadStatusRaw($context);
        $isUnset = $context->builder->icmp(Builder::INT_EQ, $status, $i32->constInt(0, false));
        $needStatus = $context->builder->not($isUnset);
        $context->builder->branchIf($needStatus, $statusBb, $skipStatus);
        $context->builder->positionAtEnd($statusBb);
        $statusBuf = $context->builder->alloca($context->getTypeFromString('int8')->arrayType(64), 1);
        $statusBufPtr = $context->builder->pointerCast($statusBuf, $i8p);
        $statusLen = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $statusBufPtr,
            $sizeT->constInt(64, false),
            self::literalCstr($context, "Status: %d\r\n"),
            $status
        );
        self::emitWriteStdout($context, $statusBufPtr, $statusLen);
        $context->builder->store($i32->constInt(1, false), $wroteSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($skipStatus);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $count = $context->builder->load(self::countPtr($context));
        $idx = $context->builder->load($idxSlot);
        $more = $context->builder->icmp(Builder::INT_SLT, $idx, $count);
        $context->builder->branchIf($more, $loopBody, $trail);

        $context->builder->positionAtEnd($loopBody);
        $line = self::loadLineAt($context, $idx);
        $isNullLine = $context->builder->icmp(Builder::INT_EQ, $line, $context->getTypeFromString('__string__*')->constNull());
        $printBb = $fn->appendBasicBlock('ph_flush_print_'.++self::$blockSuffix);
        $skipPrintBb = $fn->appendBasicBlock('ph_flush_skip_print_'.self::$blockSuffix);
        $context->builder->branchIf($isNullLine, $skipPrintBb, $printBb);
        $context->builder->positionAtEnd($printBb);
        $len = self::stringLen($context, $line);
        $data = self::stringData($context, $line);
        self::emitWriteStdout($context, $data, $len);
        self::emitWriteLiteral($context, "\r\n");
        $context->builder->store($i32->constInt(1, false), $wroteSlot);
        $context->builder->branch($skipPrintBb);
        $context->builder->positionAtEnd($skipPrintBb);
        $context->builder->store(
            $context->builder->add($idx, $i32->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($trail);
        $wrote = $context->builder->load($wroteSlot);
        $needCrLf = $context->builder->icmp(Builder::INT_NE, $wrote, $i32->constInt(0, false));
        $emitCrLf = $fn->appendBasicBlock('ph_flush_crlf');
        $exit = $fn->appendBasicBlock('ph_flush_exit');
        $context->builder->branchIf($needCrLf, $emitCrLf, $exit);
        $context->builder->positionAtEnd($emitCrLf);
        self::emitWriteLiteral($context, "\r\n");
        $context->builder->branch($exit);
        $context->builder->positionAtEnd($exit);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    /**
     * Emit queued header lines only for simulated/real web requests (#634, #4037).
     *
     * CLI-style AOT runs (no REQUEST_METHOD / GATEWAY_INTERFACE / QUERY_STRING) still
     * queue header() for headers_list gating but must not write CGI lines to stdout.
     */
    private static function branchIfWebResponseEnvPresent(
        Context $context,
        LlvmFunction $fn,
        BasicBlock $whenPresent,
        BasicBlock $whenAbsent
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');
        $keys = ['REQUEST_METHOD', 'GATEWAY_INTERFACE', 'QUERY_STRING'];
        $cursor = $fn->getLastBasicBlock();
        foreach ($keys as $idx => $key) {
            $checkEmpty = $fn->appendBasicBlock('ph_flush_env_empty_'.++self::$blockSuffix);
            $next = ($idx === \count($keys) - 1)
                ? $whenAbsent
                : $fn->appendBasicBlock('ph_flush_env_next_'.self::$blockSuffix);
            $context->builder->positionAtEnd($cursor);
            $env = $context->builder->call(
                $context->lookupFunction('getenv'),
                self::literalCstr($context, $key)
            );
            $isNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
            $context->builder->branchIf($isNull, $next, $checkEmpty);
            $context->builder->positionAtEnd($checkEmpty);
            $isEmpty = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($env),
                $i8->constInt(0, false)
            );
            $context->builder->branchIf($isEmpty, $next, $whenPresent);
            $cursor = $next;
        }
    }

    private static function emitWriteStdout(Context $context, Value $data, Value $len): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $context->lookupFunction('write'),
            $i32->constInt(1, false),
            $context->builder->pointerCast($data, $i8p),
            $context->builder->zExt($len, $i64)
        );
    }

    private static function emitWriteLiteral(Context $context, string $text): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::emitWriteStdout(
            $context,
            self::literalCstr($context, $text),
            $i64->constInt(\strlen($text), false)
        );
    }

    private static function implementSetcookieAdd(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = self::fn(
            $context,
            '__phpc_setcookie_add',
            $context->context->voidType(),
            false,
            $strPtr,
            $strPtr,
            $i64,
            $strPtr,
            $strPtr,
            $i32,
            $i32,
            $strPtr,
            $i32
        );
        $entry = $fn->appendBasicBlock('sc_entry');
        $skip = $fn->appendBasicBlock('sc_skip');
        $work = $fn->appendBasicBlock('sc_work');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $name, $strPtr->constNull());
        $context->builder->branchIf($isNull, $skip, $work);

        $context->builder->positionAtEnd($work);
        $value = $fn->getParam(1);
        $expires = $fn->getParam(2);
        $path = $fn->getParam(3);
        $domain = $fn->getParam(4);
        $secure = $fn->getParam(5);
        $httponly = $fn->getParam(6);
        $samesite = $fn->getParam(7);
        $partitioned = $fn->getParam(8);

        $bufTy = $context->getTypeFromString('int8')->arrayType(2048);
        $buf = $context->builder->alloca($bufTy, 1);
        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($i64->constInt(0, false), $posSlot);

        $nameLen = self::stringLen($context, $name);
        $nameData = self::stringData($context, $name);
        $valueLen = self::stringLen($context, $value);
        $valueData = self::stringData($context, $value);
        $fmtBase = self::literalCstr($context, 'Set-Cookie: %.*s=%.*s');
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $context->builder->pointerCast($buf, $context->getTypeFromString('char*')),
            $context->getTypeFromString('size_t')->constInt(2048, false),
            $fmtBase,
            $context->builder->trunc($nameLen, $i32),
            $nameData,
            $context->builder->trunc($valueLen, $i32),
            $valueData
        );
        $posSlot = $context->builder->alloca($i64, 1);
        $written = $context->builder->call(
            $context->lookupFunction('strlen'),
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*'))
        );
        $context->builder->store($context->builder->zExt($written, $i64), $posSlot);

        $posAfterExpires = self::appendSetcookieExpires($context, $fn, $buf, $posSlot, $expires);
        $posAfterPath = self::appendSetcookiePart($context, $fn, $buf, $posAfterExpires, $path, '; path=', 7);
        $posAfterDomain = self::appendSetcookiePart($context, $fn, $buf, $posAfterPath, $domain, '; domain=', 9);
        $posAfterFlags = self::appendSetcookieFlags($context, $fn, $buf, $posAfterDomain, $secure, $httponly);
        $posAfterSamesite = self::appendSetcookiePart($context, $fn, $buf, $posAfterFlags, $samesite, '; samesite=', 11);
        $posAfterPartitioned = self::appendSetcookieLiteralFlag(
            $context,
            $fn,
            $buf,
            $posAfterSamesite,
            $partitioned,
            '; partitioned',
            14
        );

        $lineLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*'))
        );
        $line = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($lineLen, $i64),
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*'))
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_pending_header_add'),
            $line,
            $i32->constInt(0, false)
        );
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function appendSetcookieExpires(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $posSlot,
        Value $expires
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $skip = $fn->appendBasicBlock('sc_exp_skip_'.++self::$blockSuffix);
        $work = $fn->appendBasicBlock('sc_exp_work_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('sc_exp_done_'.self::$blockSuffix);

        $gtZero = $context->builder->icmp(
            Builder::INT_SGT,
            $expires,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($gtZero, $work, $skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($work);
        $pos = $context->builder->load($posSlot);
        $dest = $context->builder->inBoundsGEP(
            $context->builder->pointerCast($buf, $i8p),
            $context->builder->trunc($pos, $i64)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $dest,
            self::literalCstr($context, '; expires='),
            $sizeT->constInt(10, false)
        );
        $pos = $context->builder->add($pos, $i64->constInt(10, false));

        $tsSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($expires, $tsSlot);
        $tmPtr = $context->builder->call(
            $context->lookupFunction('gmtime'),
            $context->builder->pointerCast($tsSlot, $i64p)
        );

        $dateBuf = $context->builder->alloca($context->getTypeFromString('int8')->arrayType(64), 1);
        $datePtr = $context->builder->pointerCast($dateBuf, $i8p);
        $context->builder->call(
            $context->lookupFunction('strftime'),
            $datePtr,
            $sizeT->constInt(64, false),
            self::literalCstr($context, '%a, %d-%b-%Y %H:%M:%S'),
            $tmPtr
        );

        $dest2 = $context->builder->inBoundsGEP(
            $context->builder->pointerCast($buf, $i8p),
            $context->builder->trunc($pos, $i64)
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $context->builder->pointerCast($dest2, $context->getTypeFromString('char*')),
            $sizeT->constInt(128, false),
            self::literalCstr($context, '%s GMT'),
            $datePtr
        );
        $context->builder->store(
            $context->builder->add($pos, $context->builder->zExt($written, $i64)),
            $posSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $posSlot;
    }

    private static function appendSetcookiePart(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $posSlot,
        Value $part,
        string $prefix,
        int $prefixLen
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $skip = $fn->appendBasicBlock('sc_part_skip_'.++self::$blockSuffix);
        $work = $fn->appendBasicBlock('sc_part_work_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('sc_part_done_'.self::$blockSuffix);
        $i64 = $context->getTypeFromString('int64');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $part, $strPtr->constNull());
        $partLen = self::stringLen($context, $part);
        $empty = $context->builder->icmp(Builder::INT_EQ, $partLen, $i64->constInt(0, false));
        $skipPart = $context->builder->or($isNull, $empty);
        $context->builder->branchIf($skipPart, $skip, $work);
        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($work);
        $pos = $context->builder->load($posSlot);
        $dest = $context->builder->inBoundsGEP(
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*')),
            $context->builder->trunc($pos, $i64)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $dest,
            self::literalCstr($context, $prefix),
            $context->getTypeFromString('size_t')->constInt($prefixLen, false)
        );
        $pos = $context->builder->add($pos, $i64->constInt($prefixLen, false));
        $partData = self::stringData($context, $part);
        $dest2 = $context->builder->inBoundsGEP(
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*')),
            $context->builder->trunc($pos, $i64)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $dest2,
            $partData,
            $context->builder->zExt($partLen, $context->getTypeFromString('size_t'))
        );
        $context->builder->store(
            $context->builder->add($pos, $context->builder->sext($partLen, $i64)),
            $posSlot
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $posSlot;
    }

    private static function appendSetcookieFlags(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $posSlot,
        Value $secure,
        Value $httponly
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $pos = self::appendSetcookieLiteralFlag($context, $fn, $buf, $posSlot, $secure, '; secure', 8);
        $pos = self::appendSetcookieLiteralFlag($context, $fn, $buf, $pos, $httponly, '; httponly', 10);

        return $pos;
    }

    private static function appendSetcookieLiteralFlag(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $posSlot,
        Value $flag,
        string $text,
        int $len
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $skip = $fn->appendBasicBlock('sc_flag_skip_'.++self::$blockSuffix);
        $work = $fn->appendBasicBlock('sc_flag_work_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('sc_flag_done_'.self::$blockSuffix);
        $isOn = $context->builder->icmp(Builder::INT_NE, $flag, $i32->constInt(0, false));
        $context->builder->branchIf($isOn, $work, $skip);
        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($work);
        $pos = $context->builder->load($posSlot);
        $dest = $context->builder->inBoundsGEP(
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*')),
            $context->builder->trunc($pos, $i64)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $dest,
            self::literalCstr($context, $text),
            $context->getTypeFromString('size_t')->constInt($len, false)
        );
        $context->builder->store($context->builder->add($pos, $i64->constInt($len, false)), $posSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $posSlot;
    }

    private static function maybeSetLocationStatus(Context $context, Value $line): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $status = HttpResponseRuntime::loadStatusRaw($context);
        $isUnset = $context->builder->icmp(Builder::INT_EQ, $status, $i32->constInt(0, false));
        $len = self::stringLen($context, $line);
        $longEnough = $context->builder->icmp(Builder::INT_SGE, $len, $i64->constInt(9, false));
        $data = self::stringData($context, $line);
        $prefix = self::literalCstr($context, 'Location:');
        $cmp = $context->builder->call(
            $context->lookupFunction('strncasecmp'),
            $data,
            $prefix,
            $context->getTypeFromString('size_t')->constInt(9, false)
        );
        $isLoc = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $set302 = $context->builder->and($isUnset, $context->builder->and($longEnough, $isLoc));
        $newStatus = $context->builder->select(
            $set302,
            $i32->constInt(302, false),
            $status
        );
        HttpResponseRuntime::storeStatusRaw($context, $newStatus);
    }

    private static function lineHasCrlf(Context $context, LlvmFunction $fn, Value $line): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $len = self::stringLen($context, $line);
        $data = self::stringData($context, $line);
        $idxSlot = $context->builder->alloca($i64, 1);
        $foundSlot = $context->builder->alloca($i32, 1);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $context->builder->store($i32->constInt(0, false), $foundSlot);
        $loopHead = $fn->appendBasicBlock('ph_crlf_head_'.++self::$blockSuffix);
        $loopBody = $fn->appendBasicBlock('ph_crlf_body_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('ph_crlf_done_'.self::$blockSuffix);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $more = $context->builder->icmp(Builder::INT_SLT, $idx, $len);
        $context->builder->branchIf($more, $loopBody, $done);
        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load(
            $context->builder->inBoundsGEP($data, $idx)
        );
        $isCr = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(13, false));
        $isLf = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(10, false));
        $hit = $context->builder->or($isCr, $isLf);
        $afterHit = $fn->appendBasicBlock('ph_crlf_hit_'.self::$blockSuffix);
        $noHit = $fn->appendBasicBlock('ph_crlf_nohit_'.self::$blockSuffix);
        $context->builder->branchIf($hit, $afterHit, $noHit);
        $context->builder->positionAtEnd($afterHit);
        $context->builder->store($i32->constInt(1, false), $foundSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($noHit);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($done);

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($foundSlot),
            $i32->constInt(0, false)
        );
    }

    private static function headerNameMatches(Context $context, Value $name, Value $line): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $nameData = self::stringData($context, $name);
        $nameLen = self::stringLen($context, $name);
        $lineData = self::stringData($context, $line);
        $lineLen = self::stringLen($context, $line);
        $cmpLen = $context->builder->call(
            $context->lookupFunction('strncasecmp'),
            $lineData,
            $nameData,
            $context->builder->zExt($nameLen, $context->getTypeFromString('size_t'))
        );
        $prefixOk = $context->builder->icmp(Builder::INT_EQ, $cmpLen, $i32->constInt(0, false));
        $nextIdx = $nameLen;
        $hasColon = $context->builder->icmp(Builder::INT_SLT, $nextIdx, $lineLen);
        $ch = $context->builder->load(
            $context->builder->inBoundsGEP(
                $lineData,
                $context->builder->sext($nextIdx, $context->getTypeFromString('int64'))
            )
        );
        $isColon = $context->builder->icmp(
            Builder::INT_EQ,
            $ch,
            $context->getTypeFromString('int8')->constInt(58, false)
        );

        return $context->builder->and($prefixOk, $context->builder->and($hasColon, $isColon));
    }

    private static function extractHeaderNameString(Context $context, Value $line): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $data = self::stringData($context, $line);
        $idxSlot = $context->builder->alloca($i64, 1);
        $endSlot = $context->builder->alloca($i64, 1);
        $outSlot = $context->builder->alloca($strPtr, 1);
        $context->builder->store($strPtr->constNull(), $outSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof LlvmFunction);
        $loopHead = $fn->appendBasicBlock('ph_name_head_'.++self::$blockSuffix);
        $loopBody = $fn->appendBasicBlock('ph_name_body_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('ph_name_done_'.self::$blockSuffix);
        $fail = $fn->appendBasicBlock('ph_name_fail_'.self::$blockSuffix);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $lineLen = self::stringLen($context, $line);
        $more = $context->builder->icmp(Builder::INT_SLT, $idx, $lineLen);
        $context->builder->branchIf($more, $loopBody, $fail);
        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load(
            $context->builder->inBoundsGEP($data, $idx)
        );
        $isColon = $context->builder->icmp(
            Builder::INT_EQ,
            $ch,
            $context->getTypeFromString('int8')->constInt(58, false)
        );
        $found = $fn->appendBasicBlock('ph_name_found_'.self::$blockSuffix);
        $cont = $fn->appendBasicBlock('ph_name_cont_'.self::$blockSuffix);
        $context->builder->branchIf($isColon, $found, $cont);
        $context->builder->positionAtEnd($found);
        $context->builder->store($idx, $endSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($cont);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($fail);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $endIdx = $context->builder->load($endSlot);
        $hasName = $context->builder->icmp(Builder::INT_SGT, $endIdx, $i64->constInt(0, false));
        $build = $fn->appendBasicBlock('ph_name_build_'.self::$blockSuffix);
        $merge = $fn->appendBasicBlock('ph_name_merge_'.self::$blockSuffix);
        $context->builder->branchIf($hasName, $build, $merge);
        $context->builder->positionAtEnd($build);
        $nameLen = $context->builder->load($endSlot);
        $buf = $context->builder->alloca($context->getTypeFromString('int8')->arrayType(256), 1);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*')),
            $data,
            $context->builder->zExt($nameLen, $context->getTypeFromString('size_t'))
        );
        $built = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($nameLen, $i64),
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*'))
        );
        $context->builder->store($built, $outSlot);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $context->builder->load($outSlot);
    }

    private static function linesArrayType(Context $context)
    {
        return $context->getTypeFromString('__string__*')->arrayType(self::MAX_HEADERS);
    }

    private static function linesBasePtr(Context $context): Value
    {
        $global = $context->module->getNamedGlobal(self::G_LINES);
        if (null === $global) {
            throw new \LogicException('PendingHeadersStandaloneLlvm global missing: '.self::G_LINES);
        }
        $arrTy = self::linesArrayType($context);

        return $context->builder->pointerCast($global, $arrTy->pointerType(0));
    }

    private static function loadLineAt(Context $context, Value $idx): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $slot = $context->builder->inBoundsGEP(
            self::linesBasePtr($context),
            $i64->constInt(0, false),
            $context->builder->sext($idx, $i64)
        );

        return $context->builder->load($slot);
    }

    private static function storeLineAt(Context $context, Value $idx, Value $line): void
    {
        $i64 = $context->getTypeFromString('int64');
        $slot = $context->builder->inBoundsGEP(
            self::linesBasePtr($context),
            $i64->constInt(0, false),
            $context->builder->sext($idx, $i64)
        );
        $context->builder->store($line, $slot);
    }

    private static function stringLen(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($text),
            $context->getTypeFromString('char*')
        );
    }

    private static function countPtr(Context $context): Value
    {
        return self::globalPtr($context, self::G_COUNT, $context->getTypeFromString('int32'));
    }

    private static function flushedPtr(Context $context): Value
    {
        return self::globalPtr($context, self::G_FLUSHED, $context->getTypeFromString('int32'));
    }

    private static function queueEnabledPtr(Context $context): Value
    {
        return self::globalPtr($context, self::G_QUEUE_ENABLED, $context->getTypeFromString('int32'));
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('PendingHeadersStandaloneLlvm global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        foreach (
            [
                self::G_COUNT => $i32->constInt(0, false),
                self::G_FLUSHED => $i32->constInt(0, false),
                self::G_QUEUE_ENABLED => $i32->constInt(0, false),
            ] as $name => $init
        ) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($i32, $name);
                $g->setInitializer($init);
            }
        }
        if (null === $context->module->getNamedGlobal(self::G_LINES)) {
            $arrTy = $strPtr->arrayType(self::MAX_HEADERS);
            $g = $context->module->addGlobal($arrTy, self::G_LINES);
            $g->setInitializer($arrTy->constNull());
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');

        $i64p = $context->getTypeFromString('int64*');

        foreach (
            [
                ['printf', $i32, true, [$charPtr]],
                ['snprintf', $i32, true, [$charPtr, $sizeT, $charPtr]],
                ['write', $i64, false, [$i32, $i8p, $i64]],
                ['getenv', $i8p, false, [$charPtr]],
                ['strlen', $sizeT, false, [$i8p]],
                ['memcpy', $voidPtr, false, [$voidPtr, $voidPtr, $sizeT]],
                ['strncasecmp', $i32, false, [$i8p, $i8p, $sizeT]],
                ['gmtime', $i8p, false, [$i64p]],
                ['strftime', $sizeT, false, [$i8p, $sizeT, $charPtr, $i8p]],
            ] as [$name, $ret, $vararg, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, $vararg, ...$params));
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringAt', $voidTy, [$htPtr, $sizeT, $strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                '__phpc_pending_header_reset',
                '__phpc_header_queue_enable',
                '__phpc_pending_header_add',
                '__phpc_pending_header_remove',
                '__phpc_pending_header_list',
                '__phpc_response_headers_flush',
                '__phpc_setcookie_add',
                '__phpc_headers_sent',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after PendingHeadersStandaloneLlvm LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
