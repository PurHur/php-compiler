<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT pending Error buffer for readonly property writes (#1360, #9522).
 *
 * LLVM ABI bridges call compiled {@see \PHPCompiler\ext\standard\ReadonlyRaiseJitHelper} PHP; no phpc_jit_pending_* globals.
 */
final class ReadonlyRaise
{
    private const HELPER_PATH = '/ext/standard/ReadonlyRaiseJitHelper.php';

    private const RAISE_HELPER = 'PHPCompiler\\ext\\standard\\ReadonlyRaiseJitHelper::raise';

    private const CLEAR_HELPER = 'PHPCompiler\\ext\\standard\\ReadonlyRaiseJitHelper::clear';

    private const HAS_PENDING_HELPER = 'PHPCompiler\\ext\\standard\\ReadonlyRaiseJitHelper::hasPending';

    private const TAKE_MESSAGE_HELPER = 'PHPCompiler\\ext\\standard\\ReadonlyRaiseJitHelper::takeMessage';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RAISE_HELPER,
        self::CLEAR_HELPER,
        self::HAS_PENDING_HELPER,
        self::TAKE_MESSAGE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_jit_raise_logic_exception',
        'phpc_jit_clear_pending_exception',
        'phpc_jit_has_pending_exception',
        'phpc_jit_copy_pending_exception',
        'phpc_jit_abort_if_pending_logic_exception',
    ];

    private static ?int $hasPendingAddress = null;

    private static ?int $copyPendingAddress = null;

    private static ?int $clearPendingAddress = null;

    private static bool $implementing = false;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function emitRaise(Context $context, string $message): void
    {
        self::registerDeclarations($context);
        self::ensureLinked($context);
        $msgLen = $context->constantFromInteger(strlen($message), 'size_t');
        $msgCStr = $context->builder->pointerCast(
            $context->constantFromString($message),
            $context->getTypeFromString('int8*')
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            $msgCStr,
            $msgLen
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_jit_has_pending_exception');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (NestedJitCompileScope::isActive()) {
            self::registerDeclarations($context);

            return;
        }

        if (self::$implementing) {
            self::registerDeclarations($context);

            return;
        }

        self::$implementing = true;
        try {
            self::ensureJitHelperCompiled($context);
            self::implementRaiseBridge($context);
            self::implementVoidBridge($context, 'phpc_jit_clear_pending_exception', self::CLEAR_HELPER);
            self::implementHasPendingBridge($context);
            self::implementCopyPendingBridge($context);
            self::implementAbortIfPending($context);
            self::registerLinkedRuntime($context);
            $context->builder->clearInsertionPosition();
        } finally {
            self::$implementing = false;
        }
    }

    private static function implementRaiseBridge(Context $context): void
    {
        $abiName = '__compiler_jit_raise_logic_exception';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('readonly_raise_entry');
        $context->builder->positionAtEnd($entry);
        $msg = $fn->getParam(0);
        $msgLen = $fn->getParam(1);
        $msgStr = self::cstrToStringWithLength($context, $msg, $context->builder->zExt($msgLen, $i64));
        $context->builder->call(self::helperFunction($context, self::RAISE_HELPER), $msgStr);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementHasPendingBridge(Context $context): void
    {
        $abiName = 'phpc_jit_has_pending_exception';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('readonly_has_pending_entry');
        $context->builder->positionAtEnd($entry);
        $pendingRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::HAS_PENDING_HELPER),
            []
        );
        $pendingI32 = JitNestedHelperCoerce::coerceHelperScalarResult($context, $pendingRaw, $i32);
        $context->builder->returnValue($pendingI32);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementCopyPendingBridge(Context $context): void
    {
        $abiName = 'phpc_jit_copy_pending_exception';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('readonly_copy_entry');
        $context->builder->positionAtEnd($entry);
        $dest = $fn->getParam(0);
        $bufsize = $fn->getParam(1);

        $hasRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::HAS_PENDING_HELPER),
            []
        );
        $has = JitNestedHelperCoerce::coerceHelperScalarResult($context, $hasRaw, $i1);
        $noPending = $context->builder->icmp(Builder::INT_EQ, $has, $i1->constInt(0, false));
        $skipBlock = $fn->appendBasicBlock('readonly_copy_skip');
        $copyBlock = $fn->appendBasicBlock('readonly_copy_do');
        $done = $fn->appendBasicBlock('readonly_copy_done');
        $context->builder->branchIf($noPending, $skipBlock, $copyBlock);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->store($i8->constInt(0, false), $dest);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($copyBlock);
        $msgRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TAKE_MESSAGE_HELPER),
            []
        );
        $msgStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $msgRaw);
        $strMap = $context->structFieldMap['__string__'];
        $msgLen = $context->builder->load(
            $context->builder->structGep($msgStr, $strMap['length'])
        );
        $msgData = $context->builder->structGep($msgStr, $strMap['value']);
        $max = $context->constantFromInteger(511, 'size_t');
        $bufCmp = $context->builder->icmp(Builder::INT_UGT, $bufsize, $max);
        $bufOk = $fn->appendBasicBlock('readonly_copy_buf_ok');
        $bufClamp = $fn->appendBasicBlock('readonly_copy_buf_clamp');
        $context->builder->branchIf($bufCmp, $bufClamp, $bufOk);
        $context->builder->positionAtEnd($bufClamp);
        $context->builder->branch($bufOk);
        $context->builder->positionAtEnd($bufOk);
        $useLenPhi = $context->builder->phi($sizeT);
        $useLenPhi->addIncoming($bufsize, $copyBlock);
        $useLenPhi->addIncoming($max, $bufClamp);
        $msgLenSized = $context->builder->zExt($msgLen, $sizeT);
        $lenCmp = $context->builder->icmp(Builder::INT_UGT, $msgLenSized, $useLenPhi);
        $msgLenOk = $fn->appendBasicBlock('readonly_copy_msg_len_ok');
        $msgLenClamp = $fn->appendBasicBlock('readonly_copy_msg_len_clamp');
        $context->builder->branchIf($lenCmp, $msgLenClamp, $msgLenOk);
        $context->builder->positionAtEnd($msgLenClamp);
        $context->builder->branch($msgLenOk);
        $context->builder->positionAtEnd($msgLenOk);
        $copyLenPhi = $context->builder->phi($sizeT);
        $copyLenPhi->addIncoming($msgLenSized, $bufOk);
        $copyLenPhi->addIncoming($useLenPhi, $msgLenClamp);
        $context->intrinsic->memcpy(
            $dest,
            $context->builder->pointerCast($msgData, $i8p),
            $copyLenPhi,
            false
        );
        $term = $context->builder->inBoundsGEP($dest, $copyLenPhi);
        $context->builder->store($i8->constInt(0, false), $term);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementAbortIfPending(Context $context): void
    {
        $abiName = 'phpc_jit_abort_if_pending_logic_exception';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureAbortLibcDecls($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('readonly_abort_entry');
        $context->builder->positionAtEnd($entry);

        $has = $context->builder->call($context->lookupFunction('phpc_jit_has_pending_exception'));
        $noPending = $context->builder->icmp(Builder::INT_EQ, $has, $i32->constInt(0, false));
        $retBlock = $fn->appendBasicBlock('readonly_abort_ret');
        $fatalBlock = $fn->appendBasicBlock('readonly_abort_fatal');
        $context->builder->branchIf($noPending, $retBlock, $fatalBlock);

        $context->builder->positionAtEnd($fatalBlock);
        $msgBuf = $context->builder->alloca($i8->arrayType(512), 1, 'pending_msg');
        $msgPtr = $context->builder->pointerCast($msgBuf, $i8p);
        $context->builder->call(
            $context->lookupFunction('phpc_jit_copy_pending_exception'),
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
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
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

        $entry = $fn->appendBasicBlock('readonly_void_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ReadonlyRaiseJitHelper compile (#9522)');
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

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ReadonlyRaiseJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ReadonlyRaiseJitHelper.php parseAndCompile failed (#9522)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9522)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ReadonlyRaise bridge (#9522)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function cstrToStringWithLength(Context $context, Value $cstr, Value $lenI64): Value
    {
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($cstr, $charPtr)
        );
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
        $context->builder->call($context->lookupFunction('phpc_jit_clear_pending_exception'));
    }

    public static function emitAbortIfPendingForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        self::registerDeclarations($context);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_logic_exception'));
    }

    public static function registerDeclarations(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');

        $decls = [
            '__compiler_jit_raise_logic_exception' => [$void, false, [$i8p, $sizeT]],
            'phpc_jit_clear_pending_exception' => [$void, false, []],
            'phpc_jit_has_pending_exception' => [$i32, false, []],
            'phpc_jit_copy_pending_exception' => [$void, false, [$i8p, $sizeT]],
            'phpc_jit_abort_if_pending_logic_exception' => [$void, false, []],
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
        self::$hasPendingAddress = $engine->getFunctionAddress('phpc_jit_has_pending_exception');
        self::$copyPendingAddress = $engine->getFunctionAddress('phpc_jit_copy_pending_exception');
        self::$clearPendingAddress = $engine->getFunctionAddress('phpc_jit_clear_pending_exception');
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
}
