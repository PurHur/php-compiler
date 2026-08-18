<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT ABI bridges for __phpc_progress_note / SIGSEGV breadcrumbs (#9521, #19874, #23311).
 *
 * Quarantined from lib/JIT/Builtin/ProgressNoteRuntime — {@see \PHPCompiler\JIT\Builtin\ProgressNoteRuntime}
 * stays the thin orchestrator.
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer UploadTemp #23301).
 *
 * SSOT: {@see ProgressJitHelper}
 * Refs #9521, #9795, #6748
 */
final class JitProgressNoteKernel
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

    /** Stable breadcrumb buffer size (kept tiny; used by SIGSEGV handler). */
    private const BUFFER_SIZE = 256;

    private const GLOBAL_BUF = 'phpc_last_progress';

    private const GLOBAL_LEN = 'phpc_last_progress_len';

    /**
     * Keep the handler self-contained and async-signal-safe:
     * - Linux SIGSEGV is 11 (we emit IR, no libc headers/macros available).
     * - Exit status 139 matches the old C thin runtime (_exit(139)).
     */
    private const SIGSEGV = 11;
    private const SEGV_EXIT = 139;

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
        $abi = Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            ? '__phpc_progress_remember'
            : '__phpc_progress_note';
        try {
            $fn = $context->lookupFunction($abi);
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

        self::$blockSuffix = 0;
        self::$bufGlobal = null;
        self::$lenGlobal = null;
        self::ensureProgressGlobals($context);
        self::ensureBufferExternals($context);
        self::ensureSegvHandlerLinked($context);
        // Compile ProgressJitHelper before bridge emission — nested JIT during pn_bridge_body
        // corrupts the parent insert block (LLVM 9 getInsertBlock null — #8559 spine emit).
        self::ensureValueStringHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementNoteBridge($context);
        self::implementRememberBridge($context);
        self::implementStaticBridges($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /** Register Progress::{noteFunction,notePhase,noteEntry} before spine callees compile (#8560). */
    private static function registerStaticProxies(Context $context): void
    {
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

    /** SIGSEGV buffer only — no ProgressJitHelper broadcast (#11437 standalone main). */
    private static function implementRememberBridge(Context $context): void
    {
        $abiName = '__phpc_progress_remember';
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

        $entry = $fn->appendBasicBlock('pn_remember_entry');
        $done = $fn->appendBasicBlock('pn_remember_done');
        $body = $fn->appendBasicBlock('pn_remember_body');
        $context->builder->positionAtEnd($entry);

        $msg = $fn->getParam(0);
        $nullPtr = $i8p->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $msg, $nullPtr);
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        self::emitRememberToBuffer($context, $fn, $msg);
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
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23311');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureValueStringHelpers($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23311'
        );
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

        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
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

        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
        // memcpy(3) via LibcExtern::ensureMemcpyDecl after always-on drop (#31885);
        // canonical i8* ABI avoids void* NestedJIT mistyped calls (#27663).
        LibcExtern::ensureMemcpyDecl($context);
    }

    /**
     * Emit an async-signal-safe SIGSEGV handler (write(2)+_exit) in LLVM IR and
     * install it during __init__ emission. This removes the last bundled C TU
     * without growing C runtime surface (#1492).
     */
    private static function ensureSegvHandlerLinked(Context $context): void
    {
        $flag = getenv('PHP_COMPILER_PROGRESS_ABI');
        if (false !== $flag && '' !== $flag && ('0' === $flag || 'false' === strtolower($flag))) {
            return;
        }

        self::ensureSegvExternals($context);

        $handlerName = 'phpc_segv_handler';
        $probe = $context->module->getNamedFunction($handlerName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::installSegvHandlerInInit($context, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($handlerName, $ft);

        $entry = $fn->appendBasicBlock('segv_entry');
        $hasProgress = $fn->appendBasicBlock('segv_has_progress');
        $noProgress = $fn->appendBasicBlock('segv_no_progress');
        $done = $fn->appendBasicBlock('segv_done');

        $savedBuilder = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $len = $context->builder->load(self::$lenGlobal);
        $has = $context->builder->icmp(Builder::INT_UGT, $len, $zero);
        $context->builder->branchIf($has, $hasProgress, $noProgress);

        $context->builder->positionAtEnd($hasProgress);
        self::emitWriteConst($context, "phpc: fatal signal (segfault) after ");
        $bufPtr = self::bufBasePtr($context);
        self::emitWrite($context, $context->getTypeFromString('int32')->constInt(2, false), $context->bytePtr($bufPtr), $len);
        self::emitWriteConst($context, "\n");
        $context->builder->branch($done);

        $context->builder->positionAtEnd($noProgress);
        self::emitWriteConst($context, "phpc: fatal signal (segfault)\n");
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->call(
            $context->lookupFunction('_exit'),
            $context->getTypeFromString('int32')->constInt(self::SEGV_EXIT, false)
        );
        $context->builder->returnVoid();

        $context->builder = $savedBuilder;

        self::installSegvHandlerInInit($context, $fn);
    }

    private static function ensureSegvExternals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $voidPtr = $context->getTypeFromString('void*');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            'write',
            $context->context->functionType($context->getTypeFromString('int64'), false, $i32, $i8p, $sizeT)
        );
        self::ensureExternal(
            $context,
            '_exit',
            $context->context->functionType($voidTy, false, $i32)
        );

        $handlerTy = $context->context->functionType($voidTy, false, $i32);
        $handlerPtrTy = $handlerTy->pointerType(0);
        self::ensureExternal(
            $context,
            'signal',
            $context->context->functionType($voidPtr, false, $i32, $handlerPtrTy)
        );
    }

    private static function installSegvHandlerInInit(Context $context, LlvmFunction $handler): void
    {
        $i32 = $context->getTypeFromString('int32');
        $sig = $i32->constInt(self::SIGSEGV, false);
        $context->emitInInit(static function (Context $ctx) use ($sig, $handler): void {
            $ctx->builder->call($ctx->lookupFunction('signal'), $sig, $handler);
        });
    }

    private static function emitWriteConst(Context $context, string $s): void
    {
        $len = strlen($s);
        if (0 === $len) {
            return;
        }
        $sizeT = $context->getTypeFromString('size_t');
        $fd = $context->getTypeFromString('int32')->constInt(2, false);
        self::emitWrite(
            $context,
            $fd,
            $context->bytePtr($context->constantFromString($s)),
            $sizeT->constInt($len, false)
        );
    }

    private static function emitWrite(Context $context, Value $fd, Value $buf, Value $len): void
    {
        // Module-local write(2) after LibcExtern always-on drop (#31817).
        \PHPCompiler\JIT\LibcExtern::ensurePosixFd($context);
        $context->builder->call(
            $context->lookupFunction('write'),
            $fd,
            $context->builder->pointerCast($buf, $context->getTypeFromString('int8*')),
            $len
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
            throw new \LogicException('__phpc_progress_note missing after JitProgressNoteKernel bridge (#9521)');
        }
        $context->registerFunction('__phpc_progress_note', $fn);
        $remember = $context->module->getNamedFunction('__phpc_progress_remember');
        if (null !== $remember) {
            $context->registerFunction('__phpc_progress_remember', $remember);
        }
    }
}
