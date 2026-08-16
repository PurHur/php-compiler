<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ob_end_clean;
use PHPCompiler\ext\standard\ob_end_flush;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ObStackLimits;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM ObStorageGlobals stack for JIT/AOT (#5314, #27566).
 *
 * NestedJIT PHP string slots abort under thin AOT (#27566 / #4941). Append/contents
 * use {@see ObStorageGlobals} memcpy paths instead. php-src: ext/standard/output.c
 */
final class ObStorageLlvm
{
    private const G_SAPI_STARTED = '__phpc_sapi_output_started';

    private const G_IMPLICIT_FLUSH = 'phpc_ob_implicit_flush_enabled';

    private const G_SHUTDOWN_REGISTERED = '__phpc_shutdown_registered';

    private const G_URL_REWRITER_ACTIVE = '__phpc_ob_url_rewriter_active';

    private const BUF_CAP = ObStackLimits::BUF_SIZE - 1;

    /** Matches ObOutputJitHelper pushLevel(2) — URL-Rewriter (#27566). */
    public const HANDLER_URL_REWRITER = 2;

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /**
     * Full ObStorage stack into this module (#27566). Call before helper-cache ObOutput bind.
     */
    public static function implement(Context $context): void
    {
        self::$blockSuffix = 0;
        $probe = $context->module->getNamedFunction('__phpc_ob_start');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::implementObStartWithUrlRewriter($context);
            self::registerLinkedRuntime($context, false);

            return;
        }

        ObStorageGlobals::ensureGlobals($context);
        self::ensureExtraGlobals($context);
        self::ensureLibc($context);
        self::ensureValueHelpers($context);
        // NestedJIT / value helpers may declare `__compiler_is_resource` without a body
        // when HELPER_RUNTIME_O=0 (#27566 / #27090).
        if ($context->isThinStandaloneAotMain()) {
            StreamGlobalsJit::implementThinIsResource($context);
        }
        StringTriggerErrorJit::implement($context);
        self::implementAppendBytes($context);
        self::implementObStart($context);
        self::implementObStartWithUrlRewriter($context);
        self::implementObGetLevel($context);
        self::implementObBufferUsedAt($context);
        self::implementObEchoCstr($context);
        self::implementObEchoChar($context);
        self::implementObEchoLl($context);
        self::implementObEchoDouble($context);
        self::implementObEchoSubstr($context);
        self::implementObGetContents($context);
        self::implementObGetLength($context);
        self::implementObEndClean($context);
        self::implementObGetClean($context);
        self::implementObEndFlush($context);
        self::implementObGetFlush($context);
        self::implementObFlush($context);
        self::implementObClean($context);
        self::implementShutdownMarkRegistered($context);
        self::implementFlush($context);
        self::implementObEndAll($context);
        self::implementObImplicitFlush($context);
        // URL-Rewriter apply body; gz flush is a thin identity stub unless ObGzhandler linked (#27566).
        self::ensureGzhandlerFlushStub($context);
        UrlRewriterApplyRuntime::ensureLinked($context);
        self::registerLinkedRuntime($context, true);
    }

    /** Identity `__phpc_ob_gzhandler_flush` when ObGzhandlerJitRuntime is not linked yet. */
    private static function ensureGzhandlerFlushStub(Context $context): void
    {
        $abi = '__phpc_ob_gzhandler_flush';
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            $abi,
            $context->context->functionType($strPtr, false, $strPtr)
        );
        if (0 === $fn->countBasicBlocks()) {
            $entry = $fn->appendBasicBlock('ogz_flush_stub');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($fn->getParam(0));
            $context->builder->clearInsertionPosition();
        }
        $context->registerFunction($abi, $fn);
    }

    /** Ensure URL-Rewriter start ABI when the rest of the stack is already linked (#27566). */
    public static function ensureUrlRewriterStart(Context $context): void
    {
        ObStorageGlobals::ensureGlobals($context);
        self::ensureExtraGlobals($context);
        self::ensureLibc($context);
        self::implementObStartWithUrlRewriter($context);
        $fn = $context->module->getNamedFunction('__phpc_ob_start_with_url_rewriter');
        if (null !== $fn) {
            $context->registerFunction('__phpc_ob_start_with_url_rewriter', $fn);
        }
    }

    private static function implementObStart(Context $context): void
    {
        $fn = self::fn($context, '__phpc_ob_start', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('obs_entry');
        $skip = $fn->appendBasicBlock('obs_skip');
        $work = $fn->appendBasicBlock('obs_work');
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
        $lenElem = self::lenElemPtr($context, $level);
        $context->builder->store($context->getTypeFromString('int64')->constInt(0, false), $lenElem);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), self::storageRowPtr($context, $level));
        $context->builder->store(
            $i32->constInt(ObGzhandlerJitRuntime::HANDLER_NONE, false),
            ObGzhandlerJitRuntime::handlerElemPtr($context, $level)
        );
        $context->builder->store($context->builder->add($level, $i32->constInt(1, false)), $levelPtr);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObStartWithUrlRewriter(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_ob_start_with_url_rewriter');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_ob_start_with_url_rewriter', $probe);

            return;
        }
        ObStorageGlobals::ensureGlobals($context);
        UrlRewriterApplyRuntime::declareAbi($context);
        $fn = self::fn($context, '__phpc_ob_start_with_url_rewriter', $context->context->voidType(), false);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('osur_entry');
        $skip = $fn->appendBasicBlock('osur_skip');
        $work = $fn->appendBasicBlock('osur_work');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $flagPtr = self::globalPtr($context, self::G_URL_REWRITER_ACTIVE, $i32);
        $already = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($flagPtr),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($already, $skip, $work);
        $context->builder->positionAtEnd($work);
        $levelPtr = self::levelPtr($context);
        $level = $context->builder->load($levelPtr);
        $atMax = $context->builder->icmp(
            Builder::INT_SGE,
            $level,
            $i32->constInt(ObStackLimits::MAX_DEPTH, false)
        );
        $maxBb = $fn->appendBasicBlock('osur_max');
        $pushBb = $fn->appendBasicBlock('osur_push');
        $context->builder->branchIf($atMax, $maxBb, $pushBb);
        $context->builder->positionAtEnd($maxBb);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($pushBb);
        $context->builder->store($context->getTypeFromString('int64')->constInt(0, false), self::lenElemPtr($context, $level));
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), self::storageRowPtr($context, $level));
        $context->builder->store(
            $i32->constInt(self::HANDLER_URL_REWRITER, false),
            ObGzhandlerJitRuntime::handlerElemPtr($context, $level)
        );
        $context->builder->store($context->builder->add($level, $i32->constInt(1, false)), $levelPtr);
        $context->builder->store($i32->constInt(1, false), $flagPtr);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($skip);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('__phpc_ob_start_with_url_rewriter', $fn);
    }

    private static function isUrlRewriterAt(Context $context, Value $idx): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $kind = $context->builder->load(ObGzhandlerJitRuntime::handlerElemPtr($context, $idx));

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i32->constInt(self::HANDLER_URL_REWRITER, false)
        );
    }

    private static function implementObGetLevel(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $fn = self::fn($context, '__phpc_ob_get_level', $i32, false);
        $entry = $fn->appendBasicBlock('ogl_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($context->builder->load(self::levelPtr($context)));
        $context->builder->clearInsertionPosition();
    }

    private static function implementObBufferUsedAt(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = self::fn($context, '__phpc_ob_buffer_used_at', $i64, false, $i64);
        $entry = $fn->appendBasicBlock('obu_entry');
        $empty = $fn->appendBasicBlock('obu_empty');
        $work = $fn->appendBasicBlock('obu_work');
        $context->builder->positionAtEnd($entry);
        $idx = $context->builder->trunc($fn->getParam(0), $i32);
        $level = $context->builder->load(self::levelPtr($context));
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SLT, $idx, $level)
        );
        $context->builder->branchIf($inRange, $work, $empty);
        $context->builder->positionAtEnd($empty);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($work);
        $context->builder->returnValue($context->builder->load(self::lenElemPtr($context, $idx)));
        $context->builder->clearInsertionPosition();
    }

    private static function implementAppendBytes(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = self::fn($context, '__phpc_ob_append_bytes', $voidTy, false, $i8p, $sizeT);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('oab_entry');
        $done = $fn->appendBasicBlock('oab_done');
        $direct = $fn->appendBasicBlock('oab_direct');
        $buffer = $fn->appendBasicBlock('oab_buffer');
        $skip = $fn->appendBasicBlock('oab_skip');
        $route = $fn->appendBasicBlock('oab_route');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $len = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $zero = $sizeT->constInt(0, false);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $data, $i8p->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $len, $zero)
        );
        $context->builder->branchIf($bad, $skip, $route);
        $context->builder->positionAtEnd($route);
        $level = $context->builder->load(self::levelPtr($context));
        $hasBuf = $context->builder->icmp(Builder::INT_SGT, $level, $i32->constInt(0, false));
        $context->builder->branchIf($hasBuf, $buffer, $direct);

        $context->builder->positionAtEnd($direct);
        $context->builder->store($i32->constInt(1, false), self::globalPtr($context, self::G_SAPI_STARTED, $i32));
        self::emitWriteStdout($context, $data, $len);
        self::maybeImplicitFlush($context, $fn);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($buffer);
        $idx = $context->builder->sub($level, $i32->constInt(1, false));
        $pos = $context->builder->load(self::lenElemPtr($context, $idx));
        $cap = $sizeT->constInt(self::BUF_CAP, false);
        $full = $context->builder->icmp(Builder::INT_UGE, $pos, $cap);
        $append = $fn->appendBasicBlock('oab_append');
        $context->builder->branchIf($full, $done, $append);
        $context->builder->positionAtEnd($append);
        $room = $context->builder->sub($cap, $pos);
        $useLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $len, $room),
            $room,
            $len
        );
        $row = self::storageRowPtr($context, $idx);
        $dest = $context->builder->inBoundsGEP($row, $pos);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($dest),
            $context->bytePtr($data),
            $useLen
        );
        $newPos = $context->builder->add($pos, $useLen);
        $context->builder->store($newPos, self::lenElemPtr($context, $idx));
        $term = $context->builder->inBoundsGEP($row, $newPos);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $term);
        self::maybeImplicitFlush($context, $fn);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function implementObEchoCstr(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $fn = self::fn($context, '__phpc_ob_echo_cstr', $voidTy, false, $i8p);
        $entry = $fn->appendBasicBlock('oec_entry');
        $done = $fn->appendBasicBlock('oec_done');
        $context->builder->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $s, $i8p->constNull());
        $work = $fn->appendBasicBlock('oec_work');
        $context->builder->branchIf($isNull, $done, $work);
        $context->builder->positionAtEnd($work);
        $len = $context->builder->call($context->lookupFunction('strlen'), $s);
        $context->builder->call($context->lookupFunction('__phpc_ob_append_bytes'), $s, $len);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function implementObEchoChar(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = self::fn($context, '__phpc_ob_echo_char', $voidTy, false, $i8);
        $entry = $fn->appendBasicBlock('oech_entry');
        $context->builder->positionAtEnd($entry);
        $slot = $context->builder->alloca($i8, 1, 'c');
        $context->builder->store($fn->getParam(0), $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $context->builder->pointerCast($slot, $context->getTypeFromString('int8*')),
            $sizeT->constInt(1, false)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function implementObEchoSubstr(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = self::fn($context, '__phpc_ob_echo_substr', $voidTy, false, $i8p, $sizeT);
        $entry = $fn->appendBasicBlock('oes_entry');
        $done = $fn->appendBasicBlock('oes_done');
        $context->builder->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $s, $i8p->constNull());
        $work = $fn->appendBasicBlock('oes_work');
        $context->builder->branchIf($isNull, $done, $work);
        $context->builder->positionAtEnd($work);
        $context->builder->call($context->lookupFunction('__phpc_ob_append_bytes'), $s, $fn->getParam(1));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function implementObEchoLl(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $fn = self::fn($context, '__phpc_ob_echo_ll', $voidTy, false, $i64);
        $entry = $fn->appendBasicBlock('oell_entry');
        $context->builder->positionAtEnd($entry);
        self::emitSnprintfAppend($context, $fn, '%lld', $fn->getParam(0), 32);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function implementObEchoDouble(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $doubleTy = $context->getTypeFromString('double');
        $fn = self::fn($context, '__phpc_ob_echo_double', $voidTy, false, $doubleTy);
        $entry = $fn->appendBasicBlock('oed_entry');
        $context->builder->positionAtEnd($entry);
        self::emitSnprintfAppendDouble($context, $fn, $fn->getParam(0), 64);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObGetContents(Context $context): void
    {
        self::implementValueFromActiveBuffer($context, '__phpc_ob_get_contents', false, true);
    }

    private static function implementObGetLength(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = self::fn($context, '__phpc_ob_get_length', $i32, false, $valuePtr);
        $entry = $fn->appendBasicBlock('ogl2_entry');
        $fail = $fn->appendBasicBlock('ogl2_fail');
        $work = $fn->appendBasicBlock('ogl2_work');
        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        if (self::branchNoActiveBuffer($context, $fn, $out, $fail, $work)) {
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $idx = self::activeIdx($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $out,
                $context->builder->load(self::lenElemPtr($context, $idx))
            );
            $context->builder->returnValue($i32->constInt(1, false));
        }
        $context->builder->clearInsertionPosition();
    }

    private static function implementObEndClean(Context $context): void
    {
        self::implementPopBuffer(
            $context,
            '__phpc_ob_end_clean',
            false,
            false,
            ob_end_clean::NO_BUFFER_NOTICE
        );
    }

    private static function implementObGetClean(Context $context): void
    {
        self::implementPopBuffer($context, '__phpc_ob_get_clean', true, false);
    }

    private static function implementObEndFlush(Context $context): void
    {
        self::implementPopBuffer(
            $context,
            '__phpc_ob_end_flush',
            false,
            true,
            ob_end_flush::NO_BUFFER_NOTICE
        );
    }

    private static function implementObGetFlush(Context $context): void
    {
        self::implementPopBuffer($context, '__phpc_ob_get_flush', true, true);
    }

    private static function implementObFlush(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = self::fn($context, '__phpc_ob_flush', $i32, false, $valuePtr);
        $entry = $fn->appendBasicBlock('ofl_entry');
        $fail = $fn->appendBasicBlock('ofl_fail');
        $work = $fn->appendBasicBlock('ofl_work');
        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        if (self::branchNoActiveBuffer($context, $fn, $out, $fail, $work)) {
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $idx = self::activeIdx($context);
            $level = $context->builder->load(self::levelPtr($context));
            $hasParent = $context->builder->icmp(Builder::INT_SGT, $level, $i32->constInt(1, false));
            $parentBb = $fn->appendBasicBlock('ofl_parent');
            $directBb = $fn->appendBasicBlock('ofl_direct');
            $doneBb = $fn->appendBasicBlock('ofl_done');
            $context->builder->branchIf($hasParent, $parentBb, $directBb);
            $context->builder->positionAtEnd($parentBb);
            $parentIdx = $context->builder->sub($idx, $i32->constInt(1, false));
            self::mergeRowIntoRow($context, $fn, $parentIdx, $idx);
            self::clearBufferAt($context, $idx);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($directBb);
            self::emitStdoutFromRow($context, $fn, $idx);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($doneBb);
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $out,
                $i32->constInt(1, false)
            );
            $context->builder->returnValue($i32->constInt(1, false));
        }
        $context->builder->clearInsertionPosition();
    }

    private static function implementObClean(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = self::fn($context, '__phpc_ob_clean', $i32, false, $valuePtr);
        $entry = $fn->appendBasicBlock('ocl_entry');
        $fail = $fn->appendBasicBlock('ocl_fail');
        $work = $fn->appendBasicBlock('ocl_work');
        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        if (self::branchNoActiveBuffer($context, $fn, $out, $fail, $work)) {
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            self::clearBufferAt($context, self::activeIdx($context));
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $out,
                $i32->constInt(1, false)
            );
            $context->builder->returnValue($i32->constInt(1, false));
        }
        $context->builder->clearInsertionPosition();
    }

    private static function implementValueFromActiveBuffer(
        Context $context,
        string $name,
        bool $pop,
        bool $falseOnFail
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = self::fn($context, $name, $i32, false, $valuePtr);
        $entry = $fn->appendBasicBlock('ov_entry');
        $fail = $fn->appendBasicBlock('ov_fail');
        $work = $fn->appendBasicBlock('ov_work');
        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        if (self::branchNoActiveBuffer($context, $fn, $out, $fail, $work)) {
            $context->builder->positionAtEnd($fail);
            if ($falseOnFail) {
                $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            }
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $idx = self::activeIdx($context);
            if ($pop) {
                self::decLevel($context);
            }
            self::writeBufferToValue($context, $fn, $out, $idx);
            if ($pop) {
                self::clearBufferAt($context, $idx);
            }
            $context->builder->returnValue($i32->constInt(1, false));
        }
        $context->builder->clearInsertionPosition();
    }

    private static function implementPopBuffer(
        Context $context,
        string $name,
        bool $returnString,
        bool $flush,
        ?string $emptyBufferNotice = null
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = self::fn($context, $name, $i32, false, $valuePtr);
        $entry = $fn->appendBasicBlock('op_entry');
        $fail = $fn->appendBasicBlock('op_fail');
        $work = $fn->appendBasicBlock('op_work');
        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        if (self::branchNoActiveBuffer($context, $fn, $out, $fail, $work)) {
            $context->builder->positionAtEnd($fail);
            if (null !== $emptyBufferNotice) {
                self::emitObNoBufferNotice($context, $emptyBufferNotice);
            }
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $idx = self::activeIdx($context);
            self::decLevel($context);
            if ($returnString) {
                self::writeBufferToValue($context, $fn, $out, $idx);
            } else {
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $out,
                    $i32->constInt(1, false)
                );
            }
            if ($flush) {
                self::appendBufferAt($context, $fn, $idx);
            }
            self::clearBufferAt($context, $idx);
            $context->builder->returnValue($i32->constInt(1, false));
        }
        $context->builder->clearInsertionPosition();
    }

    private static function implementShutdownMarkRegistered(Context $context): void
    {
        $fn = self::fn($context, '__phpc_shutdown_mark_registered', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('osmr_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store($i32->constInt(1, false), self::globalPtr($context, self::G_SHUTDOWN_REGISTERED, $i32));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementFlush(Context $context): void
    {
        $fn = self::fn($context, '__phpc_flush', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('pf_entry');
        $context->builder->positionAtEnd($entry);
        self::emitFflushStdout($context);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObEndAll(Context $context): void
    {
        $fn = self::fn($context, '__phpc_ob_end_all', $context->context->voidType(), false);
        $entry = $fn->appendBasicBlock('oea_entry');
        $loop = $fn->appendBasicBlock('oea_loop');
        $body = $fn->appendBasicBlock('oea_body');
        $done = $fn->appendBasicBlock('oea_done');
        $context->builder->positionAtEnd($entry);
        $context->builder->branch($loop);
        $context->builder->positionAtEnd($loop);
        $i32 = $context->getTypeFromString('int32');
        $level = $context->builder->load(self::levelPtr($context));
        $hasLevel = $context->builder->icmp(Builder::INT_SGT, $level, $i32->constInt(0, false));
        $context->builder->branchIf($hasLevel, $body, $done);
        $context->builder->positionAtEnd($body);
        $idx = self::activeIdx($context);
        self::decLevel($context);
        self::appendBufferAt($context, $fn, $idx);
        self::clearBufferAt($context, $idx);
        $context->builder->branch($loop);
        $context->builder->positionAtEnd($done);
        self::emitFflushStdout($context);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObImplicitFlush(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $fn = self::fn($context, '__phpc_ob_implicit_flush', $context->context->voidType(), false, $i32);
        $entry = $fn->appendBasicBlock('oif_entry');
        $context->builder->positionAtEnd($entry);
        $enabled = $context->builder->icmp(Builder::INT_NE, $fn->getParam(0), $i32->constInt(0, false));
        $storeVal = $context->builder->select(
            $enabled,
            $i32->constInt(1, false),
            $i32->constInt(0, false)
        );
        $context->builder->store($storeVal, self::globalPtr($context, self::G_IMPLICIT_FLUSH, $i32));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitObNoBufferNotice(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function branchNoActiveBuffer(
        Context $context,
        LlvmFunction $fn,
        Value $out,
        $failBb,
        $workBb
    ): bool {
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $level = $context->builder->load(self::levelPtr($context));
        $noLevel = $context->builder->icmp(Builder::INT_SLE, $level, $i32->constInt(0, false));
        $context->builder->branchIf(
            $context->builder->or($outNull, $noLevel),
            $failBb,
            $workBb
        );

        return true;
    }

    private static function activeIdx(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $level = $context->builder->load(self::levelPtr($context));

        return $context->builder->sub($level, $i32->constInt(1, false));
    }

    private static function decLevel(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $levelPtr = self::levelPtr($context);
        $context->builder->store(
            $context->builder->sub($context->builder->load($levelPtr), $i32->constInt(1, false)),
            $levelPtr
        );
    }

    private static function writeBufferToValue(Context $context, LlvmFunction $fn, Value $out, Value $idx): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->load(self::lenElemPtr($context, $idx));
        $empty = $fn->appendBasicBlock('wab_empty_'.++self::$blockSuffix);
        $copy = $fn->appendBasicBlock('wab_copy_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('wab_done_'.self::$blockSuffix);
        $hasLen = $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasLen, $copy, $empty);
        $context->builder->positionAtEnd($empty);
        self::writeEmptyString($context, $out);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($copy);
        $plainStr = self::bufferIndexToString($context, $fn, $idx, $len);
        // php-src: ob_get_contents / ob_get_clean return raw buffer; handlers run on flush only.
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $plainStr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    /** Apply gzhandler / URL-Rewriter when the slot's handler kind requires it (#27566). */
    private static function applyHandlerToString(
        Context $context,
        LlvmFunction $fn,
        Value $idx,
        Value $plainStr
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $isGz = ObGzhandlerJitRuntime::isGzhandlerAt($context, $idx);
        $isUrl = self::isUrlRewriterAt($context, $idx);
        $gzBb = $fn->appendBasicBlock('ah_gz_'.++self::$blockSuffix);
        $urlCheck = $fn->appendBasicBlock('ah_urlcheck_'.self::$blockSuffix);
        $urlBb = $fn->appendBasicBlock('ah_url_'.self::$blockSuffix);
        $plainBb = $fn->appendBasicBlock('ah_plain_'.self::$blockSuffix);
        $joinBb = $fn->appendBasicBlock('ah_join_'.self::$blockSuffix);
        $context->builder->branchIf($isGz, $gzBb, $urlCheck);
        $context->builder->positionAtEnd($gzBb);
        $gzStr = ObGzhandlerJitRuntime::emitApplyGzhandlerToString($context, $plainStr);
        $context->builder->branch($joinBb);
        $context->builder->positionAtEnd($urlCheck);
        $context->builder->branchIf($isUrl, $urlBb, $plainBb);
        $context->builder->positionAtEnd($urlBb);
        UrlRewriterApplyRuntime::declareAbi($context);
        $urlStr = UrlRewriterApplyRuntime::emitApplyCall($context, $plainStr);
        $context->builder->branch($joinBb);
        $context->builder->positionAtEnd($plainBb);
        $context->builder->branch($joinBb);
        $context->builder->positionAtEnd($joinBb);
        $final = $context->builder->phi($strPtr, 'ah_str');
        $final->addIncoming($gzStr, $gzBb);
        $final->addIncoming($urlStr, $urlBb);
        $final->addIncoming($plainStr, $plainBb);

        return $final;
    }

    private static function bufferIndexToString(Context $context, LlvmFunction $fn, Value $idx, Value $len): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $allocLen = $context->builder->add($len, $sizeT->constInt(1, false));
        $copyBuf = $context->builder->call($context->lookupFunction('malloc'), $allocLen);
        $row = self::storageRowPtr($context, $idx);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $copyBuf,
            $context->bytePtr($row),
            $len
        );
        $term = $context->builder->inBoundsGEP($context->builder->pointerCast($copyBuf, $i8p), $len);
        $context->builder->store($i8->constInt(0, false), $term);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $context->builder->pointerCast($copyBuf, $i8p)
        );
    }

    private static function writeEmptyString(Context $context, Value $out): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $emptyStr);
    }

    private static function mergeRowIntoRow(Context $context, LlvmFunction $fn, Value $destIdx, Value $srcIdx): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $srcLen = $context->builder->load(self::lenElemPtr($context, $srcIdx));
        $empty = $fn->appendBasicBlock('mrr_empty_'.++self::$blockSuffix);
        $work = $fn->appendBasicBlock('mrr_work_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('mrr_done_'.self::$blockSuffix);
        $hasLen = $context->builder->icmp(Builder::INT_UGT, $srcLen, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasLen, $work, $empty);
        $context->builder->positionAtEnd($work);
        $destPos = $context->builder->load(self::lenElemPtr($context, $destIdx));
        $cap = $sizeT->constInt(self::BUF_CAP, false);
        $room = $context->builder->sub($cap, $destPos);
        $useLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $srcLen, $room),
            $room,
            $srcLen
        );
        $destRow = self::storageRowPtr($context, $destIdx);
        $srcRow = self::storageRowPtr($context, $srcIdx);
        $destPtr = $context->builder->inBoundsGEP($destRow, $destPos);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($destPtr),
            $context->bytePtr($srcRow),
            $useLen
        );
        $newPos = $context->builder->add($destPos, $useLen);
        $context->builder->store($newPos, self::lenElemPtr($context, $destIdx));
        $term = $context->builder->inBoundsGEP($destRow, $newPos);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $term);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitStdoutFromRow(Context $context, LlvmFunction $fn, Value $idx): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $len = $context->builder->load(self::lenElemPtr($context, $idx));
        $empty = $fn->appendBasicBlock('esr_empty_'.++self::$blockSuffix);
        $work = $fn->appendBasicBlock('esr_work_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('esr_done_'.self::$blockSuffix);
        $hasLen = $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasLen, $work, $empty);
        $context->builder->positionAtEnd($work);
        $context->builder->store($i32->constInt(1, false), self::globalPtr($context, self::G_SAPI_STARTED, $i32));
        $plainStr = self::bufferIndexToString($context, $fn, $idx, $len);
        $finalStr = self::applyHandlerToString($context, $fn, $idx, $plainStr);
        $map = $context->structFieldMap['__string__'];
        $slen = $context->builder->load($context->builder->structGep($finalStr, $map['length']));
        $sptr = $context->builder->structGep($finalStr, $map['value']);
        self::emitWriteStdout(
            $context,
            $context->builder->pointerCast($sptr, $context->getTypeFromString('int8*')),
            $context->builder->trunc($slen, $sizeT)
        );
        self::clearBufferAt($context, $idx);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function appendBufferAt(Context $context, LlvmFunction $fn, Value $idx): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->load(self::lenElemPtr($context, $idx));
        $empty = $fn->appendBasicBlock('aba_empty_'.++self::$blockSuffix);
        $work = $fn->appendBasicBlock('aba_work_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('aba_done_'.self::$blockSuffix);
        $hasLen = $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasLen, $work, $empty);
        $context->builder->positionAtEnd($work);
        $plainStr = self::bufferIndexToString($context, $fn, $idx, $len);
        $finalStr = self::applyHandlerToString($context, $fn, $idx, $plainStr);
        self::emitAppendStringBytes($context, $finalStr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitAppendStringBytes(Context $context, Value $str): void
    {
        $map = $context->structFieldMap['__string__'];
        $slen = $context->builder->load($context->builder->structGep($str, $map['length']));
        $sptr = $context->builder->structGep($str, $map['value']);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $context->builder->pointerCast($sptr, $context->getTypeFromString('int8*')),
            $context->builder->trunc($slen, $context->getTypeFromString('size_t'))
        );
    }

    private static function clearBufferAt(Context $context, Value $idx): void
    {
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store($i64->constInt(0, false), self::lenElemPtr($context, $idx));
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), self::storageRowPtr($context, $idx));
    }

    private static function emitSnprintfAppend(Context $context, LlvmFunction $fn, string $fmt, Value $arg, int $bufSize): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $buf = $context->builder->alloca($i8->arrayType($bufSize), 1, 'fmtbuf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $sizeT->constInt($bufSize, false),
            $context->builder->pointerCast($context->constantFromString($fmt), $i8p),
            $arg
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $n, $i32->constInt(0, false));
        $emit = $fn->appendBasicBlock('snp_emit_'.++self::$blockSuffix);
        $done = $fn->appendBasicBlock('snp_done_'.self::$blockSuffix);
        $context->builder->branchIf($ok, $emit, $done);
        $context->builder->positionAtEnd($emit);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $bufPtr,
            $context->builder->zExt($n, $sizeT)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitSnprintfAppendDouble(Context $context, LlvmFunction $fn, Value $arg, int $bufSize): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $buf = $context->builder->alloca($i8->arrayType($bufSize), 1, 'dblbuf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $sizeT->constInt($bufSize, false),
            // php-src zend_gcvt / (string)double cast — %.14g matches Zend, not default %G (6 sig figs).
            $context->builder->pointerCast($context->constantFromString('%.14g'), $i8p),
            $arg
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $n, $i32->constInt(0, false));
        $emit = $fn->appendBasicBlock('snpd_emit_'.++self::$blockSuffix);
        $done = $fn->appendBasicBlock('snpd_done_'.self::$blockSuffix);
        $context->builder->branchIf($ok, $emit, $done);
        $context->builder->positionAtEnd($emit);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $bufPtr,
            $context->builder->zExt($n, $sizeT)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function maybeImplicitFlush(Context $context, LlvmFunction $fn): void
    {
        $i32 = $context->getTypeFromString('int32');
        $enabled = $context->builder->load(self::globalPtr($context, self::G_IMPLICIT_FLUSH, $i32));
        $isOn = $context->builder->icmp(Builder::INT_NE, $enabled, $i32->constInt(0, false));
        $flushBb = $fn->appendBasicBlock('mif_flush_'.++self::$blockSuffix);
        $skipBb = $fn->appendBasicBlock('mif_skip_'.self::$blockSuffix);
        $doneBb = $fn->appendBasicBlock('mif_done_'.self::$blockSuffix);
        $context->builder->branchIf($isOn, $flushBb, $skipBb);
        $context->builder->positionAtEnd($flushBb);
        self::emitFflushStdout($context);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
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

    private static function emitFflushStdout(Context $context): void
    {
        try {
            $fflush = $context->lookupFunction('fflush');
        } catch (\Throwable) {
            return;
        }
        $stdout = $context->module->getNamedGlobal('stdout');
        if (null === $stdout) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call($fflush, $context->builder->pointerCast($stdout, $i8p));
    }

    private static function levelPtr(Context $context): Value
    {
        return self::globalPtr($context, ObStorageGlobals::GLOBAL_LEVEL, $context->getTypeFromString('int32'));
    }

    private static function lenElemPtr(Context $context, Value $idx): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP(
            self::lenArrayPtr($context),
            $i64->constInt(0, false),
            $context->builder->sext($idx, $i64)
        );
    }

    private static function lenArrayPtr(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return self::globalPtr(
            $context,
            ObStorageGlobals::GLOBAL_LEN,
            $i64->arrayType(ObStackLimits::MAX_DEPTH)
        );
    }

    private static function storageRowPtr(Context $context, Value $idx): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $storage = $context->module->getNamedGlobal(ObStorageGlobals::GLOBAL_STORAGE);
        if (null === $storage) {
            throw new \LogicException('ObStorageLlvm: storage global missing');
        }
        $rowTy = $i8->arrayType(ObStackLimits::BUF_SIZE);
        $storageTy = $rowTy->arrayType(ObStackLimits::MAX_DEPTH);
        $base = $context->builder->pointerCast($storage, $storageTy->pointerType(0));
        $row = $context->builder->inBoundsGEP($base, $i64->constInt(0, false), $context->builder->sext($idx, $i64));

        return $context->builder->pointerCast($row, $i8->pointerType(0));
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('ObStorageLlvm global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function ensureExtraGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        foreach (
            [
                self::G_SAPI_STARTED => 0,
                self::G_IMPLICIT_FLUSH => 0,
                self::G_SHUTDOWN_REGISTERED => 0,
                self::G_URL_REWRITER_ACTIVE => 0,
            ] as $name => $init
        ) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($i32, $name);
                $g->setInitializer($i32->constInt($init, false));
            }
        }
    }

    private static function ensureLibc(Context $context): void
    {
        // Module-local fflush after LibcExtern always-on drop (#31606).
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'write', $context->context->functionType($i64, false, $i32, $i8p, $i64));
        self::ensureExternal($context, 'fflush', $context->context->functionType($i32, false, $i8p));
        self::ensureExternal($context, 'memcpy', $context->context->functionType($i8p, false, $i8p, $i8p, $sizeT));
        self::ensureExternal($context, 'malloc', $context->context->functionType($i8p, false, $sizeT));
        self::ensureExternal(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $charPtr, $sizeT, $charPtr)
        );
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
        self::ensureExternal(
            $context,
            '__value__writeString',
            $context->context->functionType($voidTy, false, $valuePtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__value__writeBool',
            $context->context->functionType($voidTy, false, $valuePtr, $i32)
        );
        self::ensureExternal(
            $context,
            '__value__writeLong',
            $context->context->functionType($voidTy, false, $valuePtr, $i64)
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

    private static function registerLinkedRuntime(Context $context, bool $requireAll = true): void
    {
        foreach (
            [
                '__phpc_ob_start',
                '__phpc_ob_get_level',
                '__phpc_ob_buffer_used_at',
                '__phpc_ob_append_bytes',
                '__phpc_ob_echo_cstr',
                '__phpc_ob_echo_char',
                '__phpc_ob_echo_ll',
                '__phpc_ob_echo_double',
                '__phpc_ob_echo_substr',
                '__phpc_ob_get_contents',
                '__phpc_ob_get_length',
                '__phpc_ob_end_clean',
                '__phpc_ob_get_clean',
                '__phpc_ob_end_flush',
                '__phpc_ob_get_flush',
                '__phpc_ob_flush',
                '__phpc_ob_clean',
                '__phpc_shutdown_mark_registered',
                '__phpc_flush',
                '__phpc_ob_end_all',
                '__phpc_ob_implicit_flush',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                if ($requireAll) {
                    throw new \LogicException($name.' missing after ObStorageLlvm implement');
                }

                continue;
            }
            $context->registerFunction($name, $fn);
        }
        $url = $context->module->getNamedFunction('__phpc_ob_start_with_url_rewriter');
        if (null !== $url && $url->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_ob_start_with_url_rewriter', $url);
        }
    }
}
