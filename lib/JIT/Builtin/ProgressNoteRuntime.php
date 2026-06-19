<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_progress_note via ProgressJitHelper PHP (#9521, #9795).
 *
 * SIGSEGV breadcrumb buffer (phpc_last_progress) stays in thin LLVM + phpc_progress.c ABI;
 * env-file writes delegate to compiled {@see ProgressJitHelper} on JIT embed and AOT standalone.
 */
final class ProgressNoteRuntime
{
    private const HELPER_PATH = '/ext/standard/ProgressJitHelper.php';

    private const BROADCAST_HELPER = 'PHPCompiler\\ext\\standard\\ProgressJitHelper::noteBroadcast';

    private const FUNCTION_HELPER = 'PHPCompiler\\ext\\standard\\ProgressJitHelper::noteFunction';

    private const PHASE_HELPER = 'PHPCompiler\\ext\\standard\\ProgressJitHelper::notePhase';

    private const ENTRY_HELPER = 'PHPCompiler\\ext\\standard\\ProgressJitHelper::noteEntry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BROADCAST_HELPER,
        self::FUNCTION_HELPER,
        self::PHASE_HELPER,
        self::ENTRY_HELPER,
    ];

    /** Must match phpc_progress.c extern buffer size. */
    private const BUFFER_SIZE = 256;

    private const GLOBAL_BUF = 'phpc_last_progress';

    private const GLOBAL_LEN = 'phpc_last_progress_len';

    private static int $blockSuffix = 0;

    /** @var Value|null */
    private static $bufGlobal = null;

    /** @var Value|null */
    private static $lenGlobal = null;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
        self::registerStaticProxies($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function emitCall(Context $context, string $message): void
    {
        if ('' === $message) {
            return;
        }
        try {
            $fn = $context->lookupFunction('__phpc_progress_note');
        } catch (\Throwable) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $fn,
            $context->builder->pointerCast($context->constantFromString($message), $i8p)
        );
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_progress_note');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ProgressNoteRuntimeLlvm::implement($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::$blockSuffix = 0;
        self::$bufGlobal = null;
        self::$lenGlobal = null;
        self::ensureProgressGlobals($context);
        self::ensureBufferExternals($context);
        self::ensureJitHelperCompiled($context);
        self::implementNoteBridge($context);
        self::implementStaticBridges($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /** Register Progress::{noteFunction,notePhase,noteEntry} before spine callees compile (#8560). */
    private static function registerStaticProxies(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ProgressNoteRuntimeLlvm::registerStaticProxies($context);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr);
        $savedBuilder = $context->builder;
        foreach ([
            'phpcompiler\\jit\\progress::notefunction' => [self::FUNCTION_HELPER, 'phpc_progress_notefunction_stub'],
            'phpcompiler\\jit\\progress::notephase' => [self::PHASE_HELPER, 'phpc_progress_notephase_stub'],
            'phpcompiler\\jit\\progress::noteentry' => [self::ENTRY_HELPER, 'phpc_progress_noteentry_stub'],
        ] as $proxy => [$helperLogical, $internal]) {
            if ($context->functionIsRegistered($proxy)) {
                continue;
            }
            self::ensureJitHelperCompiled($context);
            $probe = $context->module->getNamedFunction($internal);
            $fn = null !== $probe && $probe->countBasicBlocks() > 0
                ? $probe
                : $context->module->addFunction($internal, $ft);
            if (0 === $fn->countBasicBlocks()) {
                self::emitStringBridge($context, $fn, self::helperFunction($context, $helperLogical));
            }
            $context->registerFunction($proxy, $fn);
            $context->functions[$proxy] = $fn;
            $context->functionProxies[$proxy] = new Call\Native($fn, $proxy, [$strPtr], []);
            $context->functionReturnType[$proxy] = 'void';
        }
        $context->builder = $savedBuilder;
    }

    private static function implementNoteBridge(Context $context): void
    {
        $abiName = '__phpc_progress_note';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('pn_bridge_entry');
        $done = $fn->appendBasicBlock('pn_bridge_done');
        $body = $fn->appendBasicBlock('pn_bridge_body');
        $context->builder->positionAtEnd($entry);

        $msg = $fn->getParam(0);
        $nullPtr = $i8p->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $msg, $nullPtr);
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        self::emitRememberToBuffer($context, $fn, $msg);
        $msgStr = self::cstrToString($context, $msg);
        $context->builder->call(self::helperFunction($context, self::BROADCAST_HELPER), $msgStr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementStaticBridges(Context $context): void
    {
        foreach ([
            'phpc_progress_notefunction_stub' => self::FUNCTION_HELPER,
            'phpc_progress_notephase_stub' => self::PHASE_HELPER,
            'phpc_progress_noteentry_stub' => self::ENTRY_HELPER,
        ] as $internal => $helperLogical) {
            $probe = $context->module->getNamedFunction($internal);
            if (null !== $probe && $probe->countBasicBlocks() > 0) {
                continue;
            }
            $voidTy = $context->getTypeFromString('void');
            $strPtr = $context->getTypeFromString('__string__*');
            $ft = $context->context->functionType($voidTy, false, $strPtr);
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction($internal, $ft);
            if (0 === $fn->countBasicBlocks()) {
                self::emitStringBridge($context, $fn, self::helperFunction($context, $helperLogical));
            }
        }
    }

    private static function emitStringBridge(Context $context, LlvmFunction $fn, LlvmFunction $helper): void
    {
        $entry = $fn->appendBasicBlock('pn_str_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $strIn = $fn->getParam(0);
        $strPtr = $strIn;
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $strMap['length'])
        );
        $strData = $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $strMap['value']),
            $context->getTypeFromString('char*')
        );
        $i64 = $context->getTypeFromString('int64');
        $phpStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $strLen,
            $strData
        );
        $context->builder->call($helper, $phpStr);
        $context->builder->returnVoid();
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ProgressJitHelper compile (#9521)');
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

        self::ensureValueStringHelpers($context);

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ProgressJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ProgressJitHelper.php parseAndCompile failed (#9521)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
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
                throw new \LogicException($lc.' was not compiled for JIT (#9521)');
            }
        }
    }

    private static function ensureProgressGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $bufType = $i8->arrayType(self::BUFFER_SIZE);

        if (null === $context->module->getNamedGlobal(self::GLOBAL_BUF)) {
            self::$bufGlobal = $context->module->addGlobal($bufType, self::GLOBAL_BUF);
            self::$bufGlobal->setInitializer($bufType->constNull());
        } else {
            self::$bufGlobal = $context->module->getNamedGlobal(self::GLOBAL_BUF);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_LEN)) {
            self::$lenGlobal = $context->module->addGlobal($sizeT, self::GLOBAL_LEN);
            self::$lenGlobal->setInitializer($sizeT->constInt(0, false));
        } else {
            self::$lenGlobal = $context->module->getNamedGlobal(self::GLOBAL_LEN);
        }
    }

    private static function bufBasePtr(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP(
            self::$bufGlobal,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
    }

    private static function emitRememberToBuffer(Context $context, LlvmFunction $fn, Value $msg): void
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $maxLen = $sizeT->constInt(self::BUFFER_SIZE - 1, false);
        $zeroByte = $i8->constInt(0, false);
        $zeroSize = $sizeT->constInt(0, false);

        $len = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $clamp = self::appendBlock($fn, 'pn_clamp_len');
        $okLen = self::appendBlock($fn, 'pn_ok_len');
        $copy = self::appendBlock($fn, 'pn_copy');
        $doCopy = self::appendBlock($fn, 'pn_do_copy');
        $skipCopy = self::appendBlock($fn, 'pn_skip_copy');
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $len, $maxLen);
        $context->builder->branchIf($tooLong, $clamp, $okLen);

        $context->builder->positionAtEnd($clamp);
        $context->builder->branch($copy);

        $context->builder->positionAtEnd($okLen);
        $context->builder->branch($copy);

        $context->builder->positionAtEnd($copy);
        $storedLen = $context->builder->phi($sizeT);
        $storedLen->addIncoming($maxLen, $clamp);
        $storedLen->addIncoming($len, $okLen);
        $context->builder->store($storedLen, self::$lenGlobal);

        $hasLen = $context->builder->icmp(Builder::INT_UGT, $storedLen, $zeroSize);
        $context->builder->branchIf($hasLen, $doCopy, $skipCopy);

        $context->builder->positionAtEnd($doCopy);
        $bufPtr = self::bufBasePtr($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($bufPtr),
            $context->bytePtr($msg),
            $storedLen
        );
        $context->builder->branch($skipCopy);

        $context->builder->positionAtEnd($skipCopy);
        $bufPtr = self::bufBasePtr($context);
        $termPtr = $context->builder->inBoundsGEP($bufPtr, $storedLen);
        $context->builder->store($zeroByte, $termPtr);
    }

    private static function appendBlock(LlvmFunction $fn, string $label): \PHPLLVM\BasicBlock
    {
        return $fn->appendBasicBlock($label.'_'.(++self::$blockSuffix));
    }

    private static function ensureBufferExternals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');

        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
    }

    private static function ensureValueStringHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $charPtr)
        );
        self::ensureExternal(
            $context,
            '__value__readString',
            $context->context->functionType($strPtr, false, $valPtr)
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
        $fn = $context->module->getNamedFunction('__phpc_progress_note');
        if (null === $fn) {
            throw new \LogicException('__phpc_progress_note missing after ProgressNoteRuntime bridge (#9521)');
        }
        $context->registerFunction('__phpc_progress_note', $fn);
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
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
