<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Pending Error for JIT property guards and engine checks (#4029, #9778).
 *
 * JIT/AOT pending buffer routes through {@see \PHPCompiler\ext\standard\ErrorRaiseJitHelper} PHP.
 */
final class ErrorRaise
{
    private const HELPER_PATH = '/ext/standard/ErrorRaiseJitHelper.php';

    private const RAISE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorRaiseJitHelper::raise';

    private const CLEAR_HELPER = 'PHPCompiler\\ext\\standard\\ErrorRaiseJitHelper::clearPending';

    private const HAS_HELPER = 'PHPCompiler\\ext\\standard\\ErrorRaiseJitHelper::hasPending';

    private const TAKE_HELPER = 'PHPCompiler\\ext\\standard\\ErrorRaiseJitHelper::takePending';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RAISE_HELPER,
        self::CLEAR_HELPER,
        self::HAS_HELPER,
        self::TAKE_HELPER,
    ];

    private static ?int $hasPendingAddress = null;

    private static ?int $copyPendingAddress = null;

    private static ?int $clearPendingAddress = null;

    private static bool $implementingBodies = false;

    public static function ensureLinked(Context $context): void
    {
        self::registerDeclarations($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implementBodies($context);
    }

    /** Post-compile link: PHP helper bridges after user IR is lowered (#9778). */
    public static function finalizeJitBodies(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        self::implementBodies($context);
    }

    private static function implementBodies(Context $context): void
    {
        if (self::$implementingBodies) {
            return;
        }

        $fn = $context->module->getNamedFunction('__compiler_jit_raise_error');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }

        self::$implementingBodies = true;
        try {
            self::implementBodiesOnce($context);
        } finally {
            self::$implementingBodies = false;
        }
    }

    private static function implementBodiesOnce(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::implementRaiseBridge($context);
        self::implementVoidBridge($context, 'phpc_jit_error_clear_pending', self::CLEAR_HELPER);
        self::implementHasBridge($context);
        self::implementCopyPendingBridge($context);
        self::implementAbortIfPending($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitRaise(Context $context, string $message): void
    {
        self::registerDeclarations($context);
        self::emitPendingMessage($context, $message);
    }

    private static function emitPendingMessage(Context $context, string $message): void
    {
        $msgLen = $context->constantFromInteger(strlen($message), 'size_t');
        $msgCStr = self::stringDataPtrFromLiteral($context, $message);
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_error'),
            $msgCStr,
            $msgLen
        );
    }

    private static function implementRaiseBridge(Context $context): void
    {
        $abiName = '__compiler_jit_raise_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null === $probe || $probe->countBasicBlocks() > 0) {
            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p, $sizeT);
        $fn = $probe;

        $entry = $fn->appendBasicBlock('er_raise_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $msgStr = self::cstrLenToString($context, $fn->getParam(0), $fn->getParam(1));
        $context->builder->call(self::helperFunction($context, self::RAISE_HELPER), $msgStr);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementHasBridge(Context $context): void
    {
        $abiName = 'phpc_jit_error_has_pending';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('er_has_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $pending = $context->builder->call(self::helperFunction($context, self::HAS_HELPER));
        $context->builder->returnValue($context->builder->zext($pending, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementCopyPendingBridge(Context $context): void
    {
        $abiName = 'phpc_jit_error_copy_pending';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('er_copy_entry');
        $hasBlock = $fn->appendBasicBlock('er_copy_has');
        $skipBlock = $fn->appendBasicBlock('er_copy_skip');
        $done = $fn->appendBasicBlock('er_copy_done');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $bufsize = $fn->getParam(1);
        $has = $context->builder->call(self::helperFunction($context, self::HAS_HELPER));
        $i1 = $context->getTypeFromString('int1');
        $context->builder->branchIf($has, $hasBlock, $skipBlock);

        $context->builder->positionAtEnd($hasBlock);
        $msgStr = $context->builder->call(self::helperFunction($context, self::TAKE_HELPER));
        self::copyStringToBuffer($context, $msgStr, $dest, $bufsize);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->store($i8->constInt(0, false), $dest);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function copyStringToBuffer(
        Context $context,
        Value $msgStr,
        Value $dest,
        Value $bufsize
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];
        $msgLen = $context->builder->load(
            $context->builder->structGep($msgStr, $strMap['length'])
        );
        $msgData = $context->builder->pointerCast(
            $context->builder->structGep($msgStr, $strMap['value']),
            $context->getTypeFromString('int8*')
        );
        $max = $context->constantFromInteger(511, 'size_t');
        $lenOk = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $msgLen, $max),
            $max,
            $msgLen
        );
        $bufClamp = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $lenOk, $bufsize),
            $bufsize,
            $lenOk
        );
        $one = $sizeT->constInt(1, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULE, $bufClamp, $one),
            $sizeT->constInt(0, false),
            $context->builder->subNoUnsignedWrap($bufClamp, $one)
        );
        $context->intrinsic->memcpy($dest, $msgData, $copyLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($dest, $copyLen)
        );
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('er_void_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function cstrLenToString(Context $context, Value $cstr, Value $len): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $max = $context->constantFromInteger(511, 'size_t');
        $clamped = $context->builder->select(
            $context->builder->icmp(PHPLLVM\Builder::INT_UGT, $len, $max),
            $max,
            $len
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($clamped, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function implementAbortIfPending(Context $context): void
    {
        if (null === $context->module->getNamedFunction('phpc_jit_abort_if_pending_error')
            || 0 < $context->module->getNamedFunction('phpc_jit_abort_if_pending_error')->countBasicBlocks()
        ) {
            return;
        }

        self::ensureAbortLibcDecls($context);

        $abortFn = $context->lookupFunction('phpc_jit_abort_if_pending_error');
        $entry = $abortFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        $has = $context->builder->call($context->lookupFunction('phpc_jit_error_has_pending'));
        $noPending = $context->builder->icmp(PHPLLVM\Builder::INT_EQ, $has, $i32->constInt(0, false));
        $retBlock = $abortFn->appendBasicBlock('no_pending');
        $fatalBlock = $abortFn->appendBasicBlock('fatal');
        $context->builder->branchIf($noPending, $retBlock, $fatalBlock);

        $context->builder->positionAtEnd($fatalBlock);
        $msgBuf = $context->builder->alloca($i8->arrayType(512), 1, 'pending_msg');
        $msgPtr = $context->builder->pointerCast($msgBuf, $i8p);
        $context->builder->call(
            $context->lookupFunction('phpc_jit_error_copy_pending'),
            $msgPtr,
            $context->constantFromInteger(512, 'size_t')
        );

        $lineBuf = $context->builder->alloca($i8->arrayType(512), 1, 'fatal_line');
        $linePtr = $context->builder->pointerCast($lineBuf, $i8p);
        $stderrPtr = StringTriggerErrorJit::stderrFilePtr($context);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $linePtr,
            $context->constantFromInteger(512, 'size_t'),
            $context->builder->pointerCast(
                $context->constantFromString('PHP Fatal error:  Uncaught Error: %s\n'),
                $i8p
            ),
            $msgPtr
        );
        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderrPtr,
            $context->builder->pointerCast(
                $context->constantFromString('%s'),
                $i8p
            ),
            $linePtr
        );
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(255, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($retBlock);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function ensureAbortLibcDecls(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $void = $context->context->voidType();
        $sizeT = $context->getTypeFromString('size_t');

        if (null === $context->module->getNamedGlobal('stderr')) {
            $context->module->addGlobal($i8p, 'stderr');
        }

        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'exit',
            $context->context->functionType($void, false, $i32)
        );
    }

    public static function emitClearForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        self::registerDeclarations($context);
        self::implementBodies($context);
        $context->builder->call($context->lookupFunction('phpc_jit_error_clear_pending'));
    }

    public static function emitAbortIfPendingForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        self::registerDeclarations($context);
        self::implementBodies($context);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
    }

    public static function registerDeclarations(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');

        $decls = [
            '__compiler_jit_raise_error' => [$void, false, [$i8p, $sizeT]],
            'phpc_jit_error_clear_pending' => [$void, false, []],
            'phpc_jit_error_has_pending' => [$i32, false, []],
            'phpc_jit_error_copy_pending' => [$void, false, [$i8p, $sizeT]],
            'phpc_jit_abort_if_pending_error' => [$void, false, []],
        ];
        foreach ($decls as $name => [$ret, $vararg, $params]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    public static function bindJitEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        self::$hasPendingAddress = $engine->getFunctionAddress('phpc_jit_error_has_pending');
        self::$copyPendingAddress = $engine->getFunctionAddress('phpc_jit_error_copy_pending');
        self::$clearPendingAddress = $engine->getFunctionAddress('phpc_jit_error_clear_pending');
    }

    public static function clearPendingAtRunEntry(): void
    {
        if (null === self::$clearPendingAddress || 0 === self::$clearPendingAddress) {
            return;
        }
        $cb = self::callableFromAddress('void(*)()', self::$clearPendingAddress);
        $cb();
    }

    public static function throwPendingIfAny(): void
    {
        if (null === self::$hasPendingAddress || 0 === self::$hasPendingAddress
            || null === self::$copyPendingAddress || 0 === self::$copyPendingAddress
        ) {
            return;
        }
        $has = self::callableFromAddress('int(*)()', self::$hasPendingAddress);
        if (0 === $has()) {
            return;
        }
        $buf = \FFI::new('char[512]');
        $copy = self::callableFromAddress('void(*)(char*, size_t)', self::$copyPendingAddress);
        $copy($buf, 512);
        $msg = \FFI::string($buf);
        if ('' !== $msg) {
            throw new \Error($msg);
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ErrorRaiseJitHelper compile (#9778)');
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
        self::ensureHashtableHelpers($context);
        Builtin\CallArgv::implement($context);

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $realPath = \realpath($path) ?: $path;
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $savedBlockStorage = $context->scope->blockStorage;
        $savedBlockEntryStorage = $context->scope->blockEntryStorage;
        $context->scope->blockStorage = new \SplObjectStorage();
        $context->scope->blockEntryStorage = new \SplObjectStorage();
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $context->builder->clearInsertionPosition();
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ErrorRaiseJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ErrorRaiseJitHelper.php parseAndCompile failed (#9778)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
            $context->markJitIncludedFileCompiled($realPath);
        } finally {
            $context->scope->blockStorage = $savedBlockStorage;
            $context->scope->blockEntryStorage = $savedBlockEntryStorage;
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
                throw new \LogicException($lc.' was not compiled for JIT (#9778)');
            }
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        TypeErrorRaise::ensureDeclInScope(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
    }

    private static function ensureValueStringHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        TypeErrorRaise::ensureDeclInScope(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $charPtr)
        );
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

    /**
     * @return callable
     */
    private static function callableFromAddress(string $ctype, int $address): callable
    {
        $code = \FFI::new('uintptr_t');
        $code->cdata = $address;
        $cb = \FFI::new($ctype);
        \FFI::memcpy(\FFI::addr($cb), \FFI::addr($code), \FFI::sizeof($cb));

        return $cb;
    }

    private static function stringDataPtrFromLiteral(Context $context, string $message): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($message),
            $context->getTypeFromString('int8*')
        );
    }
}
