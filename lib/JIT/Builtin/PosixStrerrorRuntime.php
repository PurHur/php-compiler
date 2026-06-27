<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_strerror() via PosixStrerrorJitHelper PHP (#12477).
 *
 * Standalone AOT keeps libc strerror LLVM in {@see \PHPCompiler\ext\posix\JitPosix::strerror()}.
 * SSOT: {@see \PHPCompiler\ext\posix\VmPosixStrerrorPure}
 */
final class PosixStrerrorRuntime
{
    private const ABI_MESSAGE = '__posix_strerror__message';

    private const HELPER_PATH = '/ext/posix/PosixStrerrorJitHelper.php';

    private const MESSAGE_HELPER = 'PHPCompiler\\ext\\posix\\PosixStrerrorJitHelper::message';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MESSAGE_HELPER,
    ];

    public static function strerror(Context $context, JITVariable $errnoArg): Value
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return \PHPCompiler\ext\posix\JitPosix::strerrorStandalone($context, $errnoArg);
        }

        self::ensureLinked($context);
        $errno = JitLongArg::lower($context, $errnoArg, 'posix_strerror() errno');
        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $errnoI32 = $errno->typeOf() === $i32
            ? $errno
            : $context->builder->trunc($errno, $i32);

        $negBlock = BasicBlockHelper::append($context, 'posix_strerror_php_neg');
        $okBlock = BasicBlockHelper::append($context, 'posix_strerror_php_ok');
        $doneBlock = BasicBlockHelper::append($context, 'posix_strerror_php_done');

        $isNeg = $context->builder->icmp(Builder::INT_SLT, $errnoI32, $zeroI32);
        $context->builder->branchIf($isNeg, $negBlock, $okBlock);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');

        $context->builder->positionAtEnd($negBlock);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $errnoI64 = $errno->typeOf() === $i64
            ? $errno
            : $context->builder->zExt($errnoI32, $i64);
        $msgStr = $context->builder->call(
            $context->lookupFunction(self::ABI_MESSAGE),
            $errnoI64
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $msgStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_MESSAGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MESSAGE,
            'posix_strerror_message_bridge_entry',
            [$i64],
            $strPtr,
            self::MESSAGE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12477'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_MESSAGE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_MESSAGE.' missing after PosixStrerrorRuntime bridge (#12477)');
        }
        $context->registerFunction(self::ABI_MESSAGE, $fn);
    }
}
