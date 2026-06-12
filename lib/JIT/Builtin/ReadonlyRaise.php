<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM;

/**
 * Pending LogicException for JIT readonly property writes (issue #1360).
 */
final class ReadonlyRaise
{
    private static ?int $hasPendingAddress = null;

    private static ?int $copyPendingAddress = null;

    private static ?int $clearPendingAddress = null;

    public static function ensureLinked(Context $context): void
    {
        self::registerPendingGlobals($context);
        self::registerDeclarations($context);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            self::implementBodies($context);
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::registerDeclarations($context);
        self::implementBodies($context);
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

    private static function implementBodies(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_jit_raise_logic_exception');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            self::registerPendingGlobals($context);

            return;
        }

        self::registerPendingGlobals($context);
        self::implementRaiseFunction($context);
        self::implementPendingHelpers($context);
        self::implementAbortIfPending($context);
    }

    private static function registerPendingGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $msgTy = $i8->arrayType(512);
        if (null === $context->module->getNamedGlobal('phpc_jit_pending_flag')) {
            $flag = $context->module->addGlobal($i8, 'phpc_jit_pending_flag');
            $flag->setInitializer($i8->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal('phpc_jit_pending_msg')) {
            $msgGlobal = $context->module->addGlobal($msgTy, 'phpc_jit_pending_msg');
            $msgGlobal->setInitializer($msgTy->constNull());
        }
    }

    private static function implementRaiseFunction(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_jit_raise_logic_exception');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $msg = $fn->getParam(0);
        $len = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $msgGlobal = $context->module->getNamedGlobal('phpc_jit_pending_msg');
        $flagGlobal = $context->module->getNamedGlobal('phpc_jit_pending_flag');
        $msgPtr = $context->builder->pointerCast($msgGlobal, $i8p);
        $max = $context->constantFromInteger(511, 'size_t');
        $copyLen = $len;
        $cmp = $context->builder->icmp(PHPLLVM\Builder::INT_UGT, $len, $max);
        $fnParent = $fn;
        $lenOk = $fnParent->appendBasicBlock('len_ok');
        $lenClamp = $fnParent->appendBasicBlock('len_clamp');
        $done = $fnParent->appendBasicBlock('raise_done');
        $context->builder->branchIf($cmp, $lenClamp, $lenOk);
        $context->builder->positionAtEnd($lenClamp);
        $context->builder->branch($lenOk);
        $context->builder->positionAtEnd($lenOk);
        $copyLenPhi = $context->builder->phi($len->typeOf());
        $copyLenPhi->addIncoming($len, $entry);
        $copyLenPhi->addIncoming($max, $lenClamp);
        $context->intrinsic->memcpy($msgPtr, $msg, $copyLenPhi, false);
        $nullTerm = $context->builder->inBoundsGEP($msgPtr, $copyLenPhi);
        $context->builder->store($i8->constInt(0, false), $nullTerm);
        $context->builder->store(
            $i8->constInt(1, false),
            $context->builder->pointerCast($flagGlobal, $i8p)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementPendingHelpers(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        if (null === $context->module->getNamedFunction('phpc_jit_clear_pending_exception')
            || 0 === $context->module->getNamedFunction('phpc_jit_clear_pending_exception')->countBasicBlocks()
        ) {
            $clear = $context->lookupFunction('phpc_jit_clear_pending_exception');
            $block = $clear->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $flag = $context->module->getNamedGlobal('phpc_jit_pending_flag');
            $context->builder->store(
                $i8->constInt(0, false),
                $context->builder->pointerCast($flag, $i8p)
            );
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_has_pending_exception')
            || 0 === $context->module->getNamedFunction('phpc_jit_has_pending_exception')->countBasicBlocks()
        ) {
            $has = $context->lookupFunction('phpc_jit_has_pending_exception');
            $block = $has->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $flag = $context->module->getNamedGlobal('phpc_jit_pending_flag');
            $loaded = $context->builder->load($context->builder->pointerCast($flag, $i8p));
            $result = $context->builder->zext($loaded, $i32);
            $context->builder->returnValue($result);
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_copy_pending_exception')
            || 0 === $context->module->getNamedFunction('phpc_jit_copy_pending_exception')->countBasicBlocks()
        ) {
            $copyFn = $context->lookupFunction('phpc_jit_copy_pending_exception');
            $block = $copyFn->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $dest = $copyFn->getParam(0);
            $bufsize = $copyFn->getParam(1);
            $flag = $context->module->getNamedGlobal('phpc_jit_pending_flag');
            $msgGlobal = $context->module->getNamedGlobal('phpc_jit_pending_msg');
            $msgPtr = $context->builder->pointerCast($msgGlobal, $i8p);
            $flagLoaded = $context->builder->load($context->builder->pointerCast($flag, $i8p));
            $has = $context->builder->icmp(PHPLLVM\Builder::INT_NE, $flagLoaded, $i8->constInt(0, false));
            $copyBlock = $copyFn->appendBasicBlock('copy');
            $skipBlock = $copyFn->appendBasicBlock('skip');
            $done = $copyFn->appendBasicBlock('done');
            $context->builder->branchIf($has, $copyBlock, $skipBlock);
            $context->builder->positionAtEnd($copyBlock);
            $max = $context->constantFromInteger(511, 'size_t');
            $useLen = $bufsize;
            $cmp = $context->builder->icmp(PHPLLVM\Builder::INT_UGT, $bufsize, $max);
            $lenOk = $copyFn->appendBasicBlock('copy_len_ok');
            $lenClamp = $copyFn->appendBasicBlock('copy_len_clamp');
            $context->builder->branchIf($cmp, $lenClamp, $lenOk);
            $context->builder->positionAtEnd($lenClamp);
            $context->builder->branch($lenOk);
            $context->builder->positionAtEnd($lenOk);
            $lenPhi = $context->builder->phi($sizeT);
            $lenPhi->addIncoming($bufsize, $copyBlock);
            $lenPhi->addIncoming($max, $lenClamp);
            $context->intrinsic->memcpy($dest, $msgPtr, $lenPhi, false);
            $term = $context->builder->inBoundsGEP($dest, $lenPhi);
            $context->builder->store($i8->constInt(0, false), $term);
            $context->builder->store($i8->constInt(0, false), $context->builder->pointerCast($flag, $i8p));
            $context->builder->branch($done);
            $context->builder->positionAtEnd($skipBlock);
            $context->builder->store($i8->constInt(0, false), $dest);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementAbortIfPending(Context $context): void
    {
        if (null === $context->module->getNamedFunction('phpc_jit_abort_if_pending_logic_exception')
            || 0 < $context->module->getNamedFunction('phpc_jit_abort_if_pending_logic_exception')->countBasicBlocks()
        ) {
            return;
        }

        self::ensureAbortLibcDecls($context);

        $abortFn = $context->lookupFunction('phpc_jit_abort_if_pending_logic_exception');
        $entry = $abortFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        $has = $context->builder->call($context->lookupFunction('phpc_jit_has_pending_exception'));
        $noPending = $context->builder->icmp(PHPLLVM\Builder::INT_EQ, $has, $i32->constInt(0, false));
        $retBlock = $abortFn->appendBasicBlock('no_pending');
        $fatalBlock = $abortFn->appendBasicBlock('fatal');
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
