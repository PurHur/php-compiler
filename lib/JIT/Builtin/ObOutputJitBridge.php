<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ob_end_clean;
use PHPCompiler\ext\standard\ob_end_flush;
use PHPCompiler\ext\standard\ob_flush;
use PHPCompiler\ext\standard\ob_get_flush;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed + standalone link for ob_* stack via ObOutputJitHelper PHP (#9268, #12951).
 *
 * Replaces ~1k-line LLVM buffer stack. SSOT: {@see \PHPCompiler\ext\standard\ObOutputJitHelper}.
 * php-src: ext/standard/output.c
 */
final class ObOutputJitBridge
{
    private static int $echoBlockSuffix = 0;
    private const G_SAPI_STARTED = '__phpc_sapi_output_started';

    private const G_IMPLICIT_FLUSH = 'phpc_ob_implicit_flush_enabled';

    private const G_SHUTDOWN_REGISTERED = '__phpc_shutdown_registered';

    private const HELPER_PATH = '/ext/standard/ObOutputJitHelper.php';

    private const START_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::start';

    private const START_GZ_HELPER = 'PHPCompiler\\ext\\standard\\ObOutputJitHelper::startWithGzhandler';

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
    private const COMPILED_HELPERS = [
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

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_ob_start');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            self::ensureExtraGlobals($context);
            self::implementDeferredInventoryStubs($context);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureExtraGlobals($context);
        self::ensureLibc($context);
        self::ensureValueHelpers($context);
        StringTriggerErrorJit::implement($context);
        self::ensureJitHelperCompiled($context);

        self::implementVoidBridge($context, '__phpc_ob_start', self::START_HELPER);
        self::implementVoidBridge($context, '__phpc_ob_start_with_gzhandler', self::START_GZ_HELPER);
        self::implementI32Bridge($context, '__phpc_ob_get_level', self::LEVEL_HELPER);
        self::implementI64FromI64Bridge($context, '__phpc_ob_buffer_used_at', self::BUFFER_USED_HELPER);
        self::implementAppendBytes($context);
        self::implementObEchoCstr($context);
        self::implementObEchoChar($context);
        self::implementObEchoSubstr($context);
        self::implementObEchoLl($context);
        self::implementObEchoDouble($context);
        self::implementContentsBridge($context, '__phpc_ob_get_contents', self::CONTENTS_HELPER, false, null);
        self::implementLengthBridge($context);
        self::implementBoolResultBridge($context, '__phpc_ob_end_clean', self::END_CLEAN_HELPER, ob_end_clean::NO_BUFFER_NOTICE);
        self::implementContentsBridge($context, '__phpc_ob_get_clean', self::GET_CLEAN_HELPER, true, null);
        self::implementBoolResultBridge($context, '__phpc_ob_end_flush', self::END_FLUSH_HELPER, ob_end_flush::NO_BUFFER_NOTICE);
        self::implementContentsBridge($context, '__phpc_ob_get_flush', self::GET_FLUSH_HELPER, true, ob_get_flush::NO_BUFFER_NOTICE);
        self::implementBoolResultBridge($context, '__phpc_ob_flush', self::FLUSH_BUFFER_HELPER, ob_flush::NO_BUFFER_NOTICE);
        self::implementBoolResultBridge($context, '__phpc_ob_clean', self::CLEAN_HELPER, null);
        self::implementVoidBridge($context, '__phpc_ob_end_all', self::END_ALL_HELPER);
        self::implementVoidBridge($context, '__phpc_flush', self::FLUSH_STDOUT_HELPER);
        self::implementImplicitFlush($context);
        self::implementShutdownMarkRegistered($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
        $context->builder->clearInsertionPosition();
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

        self::ensureEchoAbiDeclared($context);

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ObOutputJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ObOutputJitHelper.php parseAndCompile failed (#9268)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9268)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                '__phpc_ob_start',
                '__phpc_ob_start_with_gzhandler',
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
                throw new \LogicException($name.' missing after ObOutputJitBridge bridge (#9268)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    /** Inventory emit only needs linkable ABI symbols — skip nested STDOUT JIT (#13301). */
    private static function implementDeferredInventoryStubs(Context $context): void
    {
        self::ensureEchoAbiDeclared($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero32 = $i32->constInt(0, false);
        $zero64 = $i64->constInt(0, false);

        $voidNames = [
            '__phpc_ob_start',
            '__phpc_ob_start_with_gzhandler',
            '__phpc_ob_append_bytes',
            '__phpc_ob_echo_cstr',
            '__phpc_ob_echo_char',
            '__phpc_ob_echo_ll',
            '__phpc_ob_echo_double',
            '__phpc_ob_echo_substr',
            '__phpc_ob_end_all',
            '__phpc_flush',
            '__phpc_shutdown_mark_registered',
            '__phpc_ob_implicit_flush',
        ];
        foreach ($voidNames as $name) {
            self::implementIfMissing($context, $name, static function (Context $context, LlvmFunction $fn): void {
                $entry = $fn->appendBasicBlock('ob_inv_void');
                $context->builder->positionAtEnd($entry);
                $context->builder->returnVoid();
            });
        }

        foreach (
            [
                '__phpc_ob_get_level',
                '__phpc_ob_get_contents',
                '__phpc_ob_get_length',
                '__phpc_ob_end_clean',
                '__phpc_ob_get_clean',
                '__phpc_ob_end_flush',
                '__phpc_ob_get_flush',
                '__phpc_ob_flush',
                '__phpc_ob_clean',
            ] as $name
        ) {
            self::implementIfMissing($context, $name, static function (Context $context, LlvmFunction $fn) use ($zero32): void {
                $entry = $fn->appendBasicBlock('ob_inv_i32');
                $context->builder->positionAtEnd($entry);
                $context->builder->returnValue($zero32);
            });
        }

        self::implementIfMissing($context, '__phpc_ob_buffer_used_at', static function (Context $context, LlvmFunction $fn) use ($zero64): void {
            $entry = $fn->appendBasicBlock('ob_inv_i64');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($zero64);
        });

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
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

    private static function implementObEchoCstr(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $fn = self::echoFn($context, '__phpc_ob_echo_cstr', $voidTy, false, $i8p);
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

    private static function implementObEchoChar(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = self::echoFn($context, '__phpc_ob_echo_char', $voidTy, false, $i8);
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

    private static function implementObEchoSubstr(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = self::echoFn($context, '__phpc_ob_echo_substr', $voidTy, false, $i8p, $sizeT);
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

    private static function implementObEchoLl(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $fn = self::echoFn($context, '__phpc_ob_echo_ll', $voidTy, false, $i64);
        $entry = $fn->appendBasicBlock('oell_entry');
        $context->builder->positionAtEnd($entry);
        self::emitSnprintfAppend($context, $fn, '%lld', $fn->getParam(0), 32);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObEchoDouble(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $doubleTy = $context->getTypeFromString('double');
        $fn = self::echoFn($context, '__phpc_ob_echo_double', $voidTy, false, $doubleTy);
        $entry = $fn->appendBasicBlock('oed_entry');
        $context->builder->positionAtEnd($entry);
        self::emitSnprintfAppendDouble($context, $fn, $fn->getParam(0), 64);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
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
        $emit = $fn->appendBasicBlock('snp_emit_'.++self::$echoBlockSuffix);
        $done = $fn->appendBasicBlock('snp_done_'.self::$echoBlockSuffix);
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
            $context->builder->pointerCast($context->constantFromString('%.14g'), $i8p),
            $arg
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $n, $i32->constInt(0, false));
        $emit = $fn->appendBasicBlock('snpd_emit_'.++self::$echoBlockSuffix);
        $done = $fn->appendBasicBlock('snpd_done_'.self::$echoBlockSuffix);
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

  /** Forward-declare echo ABI so nested ObOutputJitHelper compile can lower `echo` (#12999). */
    private static function ensureEchoAbiDeclared(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $doubleTy = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');

        self::echoFn($context, '__phpc_ob_echo_cstr', $voidTy, false, $i8p);
        self::echoFn($context, '__phpc_ob_echo_char', $voidTy, false, $i8);
        self::echoFn($context, '__phpc_ob_echo_substr', $voidTy, false, $i8p, $sizeT);
        self::echoFn($context, '__phpc_ob_echo_ll', $voidTy, false, $i64);
        self::echoFn($context, '__phpc_ob_echo_double', $voidTy, false, $doubleTy);
    }

    private static function echoFn(Context $context, string $name, $ret, bool $vararg, ...$params): LlvmFunction
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
}
