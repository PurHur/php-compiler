<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ob_end_clean;
use PHPCompiler\ext\standard\ob_end_flush;
use PHPCompiler\ext\standard\ob_flush;
use PHPCompiler\ext\standard\ob_get_flush;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed + standalone link for ob_* stack via ObOutputJitHelper PHP (#9268, #12951, #19422, #20443, #21066, #21469).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer PendingHeadersJitBridge #22034 / FunctionExistsRuntime #22016).
 * Always NestedJIT {@see ObOutputJitHelper} bridges — no user-script ExecCapture/AbiPads fork (#21469).
 * SSOT: {@see \PHPCompiler\ext\standard\ObOutputJitHelper}.
 * php-src: ext/standard/output.c
 */
final class ObOutputJitBridge
{
    private const G_SAPI_STARTED = '__phpc_sapi_output_started';

    private const G_IMPLICIT_FLUSH = 'phpc_ob_implicit_flush_enabled';

    private const G_SHUTDOWN_REGISTERED = '__phpc_shutdown_registered';

    private const HELPER_PATH = '/ext/standard/ObOutputJitHelper.php';

    private const START_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::start';

    private const START_GZ_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::startWithGzhandler';

    private const START_URL_REWRITER_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::startWithUrlRewriter';

    private const LEVEL_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::getLevel';

    private const BUFFER_USED_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::bufferUsedAt';

    private const APPEND_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::appendString';

    private const HAS_BUFFER_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::hasActiveBuffer';

    private const CONTENTS_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::getContents';

    private const LENGTH_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::getLength';

    private const END_CLEAN_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::endClean';

    private const GET_CLEAN_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::getClean';

    private const END_FLUSH_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::endFlush';

    private const GET_FLUSH_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::getFlush';

    private const FLUSH_BUFFER_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::flushBuffer';

    private const CLEAN_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::clean';

    private const END_ALL_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::endAll';

    private const IMPLICIT_FLUSH_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::setImplicitFlush';

    private const FLUSH_STDOUT_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::flushStdout';

    /** @var list<string> */
    private const COMPILED_HELPERS_BASE = [
        self::START_HELPER,
        self::START_GZ_HELPER,
        self::LEVEL_HELPER,
        self::BUFFER_USED_HELPER,
        self::APPEND_HELPER,
        self::HAS_BUFFER_HELPER,
        self::CONTENTS_HELPER,
        self::LENGTH_HELPER,
        self::END_CLEAN_HELPER,
        self::GET_CLEAN_HELPER,
        self::END_FLUSH_HELPER,
        self::GET_FLUSH_HELPER,
        self::FLUSH_BUFFER_HELPER,
        self::CLEAN_HELPER,
        self::END_ALL_HELPER,
        self::IMPLICIT_FLUSH_HELPER,
        self::FLUSH_STDOUT_HELPER,
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::START_HELPER,
        self::START_GZ_HELPER,
        self::START_URL_REWRITER_HELPER,
        self::LEVEL_HELPER,
        self::BUFFER_USED_HELPER,
        self::APPEND_HELPER,
        self::HAS_BUFFER_HELPER,
        self::CONTENTS_HELPER,
        self::LENGTH_HELPER,
        self::END_CLEAN_HELPER,
        self::GET_CLEAN_HELPER,
        self::END_FLUSH_HELPER,
        self::GET_FLUSH_HELPER,
        self::FLUSH_BUFFER_HELPER,
        self::CLEAN_HELPER,
        self::END_ALL_HELPER,
        self::IMPLICIT_FLUSH_HELPER,
        self::FLUSH_STDOUT_HELPER,
    ];

    public static function implement(Context $context): void
    {
        self::implementObStack($context, false);
    }

    /**
     * Full ob_* LLVM stack (ObOutputJitHelper nested JIT). $forceFull kept for call-site API (#10492).
     */
    public static function implementObStack(Context $context, bool $forceFull): void
    {
        unset($forceFull);
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__phpc_ob_start');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context, false);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        // Storage-backed LLVM stack — NestedJIT PHP string slots abort under thin AOT (#27566).
        ObStorageLlvm::implement($context);
        self::ensureExtraGlobals($context);
        self::ensureLibc($context);
        self::ensureValueHelpers($context);
        StringTriggerErrorJit::implement($context);
        // Keep NestedJIT ObOutputJitHelper available for helper-cache / spine (#22049);
        // ABI bodies above already come from ObStorageLlvm.
        self::ensureJitHelperCompiled($context);
        ObOutputEchoJitEmit::implementAll($context);
        self::implementImplicitFlush($context);
        self::implementShutdownMarkRegistered($context);
        self::registerLinkedRuntime($context, false);
        self::restoreInsertBlock($context, $restore);
        if (null === $restore) {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Full ObOutput stack with URL-Rewriter into this module (#27566).
     * Call before any helper-cache ObOutput bind.
     */
    public static function ensureUrlRewriterStack(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_ob_start_with_url_rewriter');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_ob_start_with_url_rewriter', $probe);
            self::registerLinkedRuntime($context, false);
            // Ob stack may already exist from Context init — still NestedJIT apply here
            // (user-script rewrite path). Init-time NestedJIT of apply is forbidden (#31099).
            UrlRewriterApplyRuntime::ensureNestedJitBridge($context);

            return;
        }
        $restore = self::captureInsertBlock($context);
        // Force ObStorage LLVM into this module (skip NestedJIT PHP string slots).
        ObStorageLlvm::implement($context);
        ObStorageLlvm::ensureUrlRewriterStart($context);
        self::ensureExtraGlobals($context);
        self::ensureLibc($context);
        self::ensureValueHelpers($context);
        StringTriggerErrorJit::implement($context);
        // NestedJIT apply during user-script rewrite path only — not Context init (#31099).
        UrlRewriterApplyRuntime::ensureNestedJitBridge($context);
        // Rewrite-vars NestedJIT only (not ObOutput string stack) — module-global TU dedupe (#27566).
        self::ensureJitHelperCompiled($context, true);
        ObOutputEchoJitEmit::implementAll($context);
        self::implementImplicitFlush($context);
        self::implementShutdownMarkRegistered($context);
        self::registerLinkedRuntime($context, false);
        self::restoreInsertBlock($context, $restore);
    }

    /**
     * Ensure `__phpc_ob_start_with_url_rewriter` when the rest of the ob stack
     * was already linked (early return / ensureObStackLinked, #27566).
     */
    public static function ensureUrlRewriterAbi(Context $context): void
    {
        self::ensureUrlRewriterStack($context);
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        self::implementIfMissing($context, $abiName, static function (Context $context, LlvmFunction $fn) use ($helperLogical): void {
            $entry = $fn->appendBasicBlock('ob_void_entry');
            $context->builder->positionAtEnd($entry);
            $context->builder->call(self::helperFunction($context, $helperLogical));
            $context->builder->returnVoid();
        });
    }

    private static function implementI32Bridge(Context $context, string $abiName, string $helperLogical): void
    {
        self::implementIfMissing($context, $abiName, static function (Context $context, LlvmFunction $fn) use ($helperLogical): void {
            $entry = $fn->appendBasicBlock('ob_i32_entry');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $raw = $context->builder->call(self::helperFunction($context, $helperLogical));
            $context->builder->returnValue($context->builder->trunc($raw, $i32));
        });
    }

    private static function implementI64FromI64Bridge(Context $context, string $abiName, string $helperLogical): void
    {
        self::implementIfMissing($context, $abiName, static function (Context $context, LlvmFunction $fn) use ($helperLogical): void {
            $entry = $fn->appendBasicBlock('ob_i64_entry');
            $context->builder->positionAtEnd($entry);
            $i64 = $context->getTypeFromString('int64');
            $result = $context->builder->call(
                self::helperFunction($context, $helperLogical),
                $fn->getParam(0)
            );
            $context->builder->returnValue($context->builder->sext($result, $i64));
        });
    }

    private static function implementContentsBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        bool $popOnSuccess,
        ?string $emptyBufferNotice = null
    ): void {
        self::implementIfMissing($context, $abiName, static function (Context $context, LlvmFunction $fn) use ($helperLogical, $popOnSuccess, $emptyBufferNotice): void {
            $entry = $fn->appendBasicBlock('ob_val_entry');
            $fail = $fn->appendBasicBlock('ob_val_fail');
            $work = $fn->appendBasicBlock('ob_val_work');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $has = $context->builder->call(self::helperFunction($context, self::HAS_BUFFER_HELPER));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->trunc($has, $i32), $i32->constInt(0, false)),
                $fail,
                $work
            );
            $context->builder->positionAtEnd($fail);
            if (null !== $emptyBufferNotice) {
                self::emitObNoBufferNotice($context, $emptyBufferNotice);
            }
            if (!$popOnSuccess) {
                $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            }
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, $helperLogical),
                []
            );
            $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
            $nullBb = $fn->appendBasicBlock('ob_val_null');
            $okBb = $fn->appendBasicBlock('ob_val_ok');
            $context->builder->branchIf($isNull, $nullBb, $okBb);
            $context->builder->positionAtEnd($nullBb);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
            $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementLengthBridge(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_length', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('ob_len_entry');
            $fail = $fn->appendBasicBlock('ob_len_fail');
            $work = $fn->appendBasicBlock('ob_len_work');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $has = $context->builder->call(self::helperFunction($context, self::HAS_BUFFER_HELPER));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->trunc($has, $i32), $i32->constInt(0, false)),
                $fail,
                $work
            );
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $len = $context->builder->call(self::helperFunction($context, self::LENGTH_HELPER));
            $neg = $context->builder->icmp(Builder::INT_SLT, $len, $i64->constInt(0, false));
            $negBb = $fn->appendBasicBlock('ob_len_neg');
            $okBb = $fn->appendBasicBlock('ob_len_ok');
            $context->builder->branchIf($neg, $negBb, $okBb);
            $context->builder->positionAtEnd($negBb);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $len);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementBoolResultBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        ?string $emptyBufferNotice
    ): void {
        self::implementIfMissing($context, $abiName, static function (Context $context, LlvmFunction $fn) use ($helperLogical, $emptyBufferNotice): void {
            $entry = $fn->appendBasicBlock('ob_bool_entry');
            $fail = $fn->appendBasicBlock('ob_bool_fail');
            $work = $fn->appendBasicBlock('ob_bool_work');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $has = $context->builder->call(self::helperFunction($context, self::HAS_BUFFER_HELPER));
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->trunc($has, $i32), $i32->constInt(0, false)),
                $fail,
                $work
            );
            $context->builder->positionAtEnd($fail);
            if (null !== $emptyBufferNotice) {
                self::emitObNoBufferNotice($context, $emptyBufferNotice);
            }
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($work);
            $ok = $context->builder->call(self::helperFunction($context, $helperLogical));
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $out,
                $context->builder->trunc($ok, $i32)
            );
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function implementAppendBytes(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_append_bytes', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oab_entry');
            $done = $fn->appendBasicBlock('oab_done');
            $skip = $fn->appendBasicBlock('oab_skip');
            $work = $fn->appendBasicBlock('oab_work');
            $context->builder->positionAtEnd($entry);
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $strPtr = $context->getTypeFromString('__string__*');
            $data = $fn->getParam(0);
            $len = $fn->getParam(1);
            $zero = $sizeT->constInt(0, false);
            $bad = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $data, $i8p->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $len, $zero)
            );
            $context->builder->branchIf($bad, $skip, $work);
            $context->builder->positionAtEnd($work);
            $chunk = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->sext($len, $i64),
                $data
            );
            $direct = $context->builder->call(
                self::helperFunction($context, self::APPEND_HELPER),
                $chunk
            );
            $isDirect = $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->trunc($direct, $i32),
                $i32->constInt(0, false)
            );
            $markBb = $fn->appendBasicBlock('oab_mark');
            $context->builder->branchIf($isDirect, $markBb, $done);
            $context->builder->positionAtEnd($markBb);
            $context->builder->store($i32->constInt(1, false), self::globalPtr($context, self::G_SAPI_STARTED, $i32));
            $context->builder->branch($done);
            $context->builder->positionAtEnd($skip);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
            $context->builder->returnVoid();
        });
    }

    private static function implementImplicitFlush(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_implicit_flush', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oif_entry');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $context->builder->call(
                self::helperFunction($context, self::IMPLICIT_FLUSH_HELPER),
                $context->builder->sext($fn->getParam(0), $context->getTypeFromString('int64'))
            );
            $enabled = $context->builder->icmp(Builder::INT_NE, $fn->getParam(0), $i32->constInt(0, false));
            $storeVal = $context->builder->select(
                $enabled,
                $i32->constInt(1, false),
                $i32->constInt(0, false)
            );
            $context->builder->store($storeVal, self::globalPtr($context, self::G_IMPLICIT_FLUSH, $i32));
            $context->builder->returnVoid();
        });
    }

    private static function implementShutdownMarkRegistered(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_shutdown_mark_registered', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('osmr_entry');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $context->builder->store($i32->constInt(1, false), self::globalPtr($context, self::G_SHUTDOWN_REGISTERED, $i32));
            $context->builder->returnVoid();
        });
    }

    private static function emitObNoBufferNotice(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $context->builder->sext($msgLen, $sizeT),
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
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
        $fn = self::declareIfMissing($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
    }

  private static function declareIfMissing(Context $context, string $name, $ret = null, bool $vararg = false, ...$params): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            return $probe;
        }
        if (null === $ret) {
            $ret = match ($name) {
                '__phpc_ob_get_level',
                '__phpc_ob_get_contents',
                '__phpc_ob_get_length',
                '__phpc_ob_end_clean',
                '__phpc_ob_get_clean',
                '__phpc_ob_end_flush',
                '__phpc_ob_get_flush',
                '__phpc_ob_flush',
                '__phpc_ob_clean' => $context->getTypeFromString('int32'),
                '__phpc_ob_buffer_used_at' => $context->getTypeFromString('int64'),
                default => $context->context->voidType(),
            };
            $params = match ($name) {
                '__phpc_ob_buffer_used_at' => [$context->getTypeFromString('int64')],
                '__phpc_ob_get_contents',
                '__phpc_ob_get_length',
                '__phpc_ob_end_clean',
                '__phpc_ob_get_clean',
                '__phpc_ob_end_flush',
                '__phpc_ob_get_flush',
                '__phpc_ob_flush',
                '__phpc_ob_clean' => [$context->getTypeFromString('__value__*')],
                '__phpc_ob_implicit_flush' => [$context->getTypeFromString('int32')],
                '__phpc_ob_append_bytes' => [
                    $context->getTypeFromString('int8*'),
                    $context->getTypeFromString('size_t'),
                ],
                default => [],
            };
        }
        $ft = $context->context->functionType($ret, $vararg, ...$params);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureExtraGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        foreach (
            [
                self::G_SAPI_STARTED => 0,
                self::G_IMPLICIT_FLUSH => 0,
                self::G_SHUTDOWN_REGISTERED => 0,
            ] as $name => $init
        ) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($i32, $name);
                $g->setInitializer($i32->constInt($init, false));
            }
        }
    }

    private static function globalPtr(Context $context, string $name, $ty): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('ObOutputJitBridge: missing global '.$name);
        }

        return $context->builder->pointerCast($global, $ty->pointerType(0));
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $doubleTy = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        foreach (
            [
                'strlen' => [$sizeT, false, $i8p],
                'snprintf' => [$i32, true, $i8p, $sizeT, $i8p],
                '__compiler_trigger_error' => [
                    $context->getTypeFromString('void'),
                    false,
                    $i8p,
                    $sizeT,
                    $i32,
                    $i8p,
                    $i32,
                ],
            ] as $name => $sig
        ) {
            $ret = $sig[0];
            $vararg = $sig[1];
            $params = array_slice($sig, 2);
            self::ensureExternal($context, $name, $context->context->functionType($ret, $vararg, ...$params));
        }
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        foreach (
            [
                '__string__init' => [$strPtr, false, $i64, $i8p],
                '__value__writeString' => [$voidTy, false, $valuePtr, $strPtr],
                '__value__writeBool' => [$voidTy, false, $valuePtr, $i32],
                '__value__writeLong' => [$voidTy, false, $valuePtr, $i64],
            ] as $name => $sig
        ) {
            self::ensureExternal($context, $name, $context->context->functionType(...$sig));
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ObOutputJitHelper compile (#9268)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context, bool $withUrlRewriter = false): void
    {
        // Echo ABI must exist before NestedJIT ObOutputJitHelper compile (#12999).
        ObOutputEchoJitEmit::ensureEchoAbiDeclared($context);
        if (!$withUrlRewriter) {
            // Plain ob_* — helper-runtime unit.o (working getLevel).
            JitVmHelperLink::ensureCompiled(
                $context,
                self::HELPER_PATH,
                self::COMPILED_HELPERS_BASE,
                '#22049'
            );

            return;
        }
        // URL-Rewriter: scanner/apply NestedJIT only — rewrite vars live in LLVM BSS (#27566).
        UrlRewriterApplyRuntime::ensureLinked($context);
    }

    /**
     * @param bool $requireAll When false, register only ABI bodies that already exist.
     */
    private static function registerLinkedRuntime(Context $context, bool $requireAll = true): void
    {
        foreach (
            [
                '__phpc_ob_start',
                '__phpc_ob_start_with_gzhandler',
                '__phpc_ob_start_with_url_rewriter',
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
                    throw new \LogicException($name.' missing after ObOutputJitBridge bridge (#9268)');
                }

                continue;
            }
            $context->registerFunction($name, $fn);
        }
    }

    /** @internal Shared preamble for exec-capture / echo emit (#13822, #19422). */
    public static function prepareUserScriptEmit(Context $context): void
    {
        self::ensureExtraGlobals($context);
        self::ensureLibc($context);
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
