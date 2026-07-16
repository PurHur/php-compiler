<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitGetcwd;
use PHPCompiler\ext\standard\JitSleep;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_getlogin()/posix_ttyname()/posix_isatty() via PosixTerminalJitHelper (#6504).
 *
 * SSOT: {@see \PHPCompiler\ext\posix\VmPosixTerminalPure}
 */
final class PosixTerminalRuntime
{
    private const ABI_GETLOGIN = '__posix_terminal__getlogin';

    private const ABI_TTYNAME = '__posix_terminal__ttyname';

    private const ABI_ISATTY = '__posix_terminal__isatty';

    private const HELPER_PATH = '/ext/posix/PosixTerminalJitHelper.php';

    private const GETLOGIN_HELPER = 'PHPCompiler\\ext\\posix\\PosixTerminalJitHelper::getlogin';

    private const TTYNAME_HELPER = 'PHPCompiler\\ext\\posix\\PosixTerminalJitHelper::ttyname';

    private const ISATTY_HELPER = 'PHPCompiler\\ext\\posix\\PosixTerminalJitHelper::isatty';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETLOGIN_HELPER,
        self::TTYNAME_HELPER,
        self::ISATTY_HELPER,
    ];

    public static function getlogin(Context $context): Value
    {
        self::ensureLinked($context);
        $msgStr = $context->builder->call(
            $context->lookupFunction(self::ABI_GETLOGIN)
        );

        return JitGetcwd::boxed($context, $msgStr);
    }

    public static function ttyname(Context $context, JITVariable $fdArg): Value
    {
        self::ensureLinked($context);
        $fd = JitSleep::zParamLong($context, $fdArg, 'posix_ttyname', 1, 'file_descriptor');
        $i64 = $context->getTypeFromString('int64');
        $fdI64 = $fd->typeOf() === $i64
            ? $fd
            : $context->builder->sext($fd, $i64);
        $msgStr = $context->builder->call(
            $context->lookupFunction(self::ABI_TTYNAME),
            $fdI64
        );

        return JitGetcwd::boxed($context, $msgStr);
    }

    public static function isatty(Context $context, JITVariable $fdArg): Value
    {
        self::ensureLinked($context);
        $fd = JitSleep::zParamLong($context, $fdArg, 'posix_isatty', 1, 'file_descriptor');
        $i64 = $context->getTypeFromString('int64');
        $fdI64 = $fd->typeOf() === $i64
            ? $fd
            : $context->builder->sext($fd, $i64);
        $raw = $context->builder->call(
            $context->lookupFunction(self::ABI_ISATTY),
            $fdI64
        );
        $slot = JitValueBox::alloc($context);
        $zero = $i64->constInt(0, false);
        $rawI64 = $raw->typeOf() === $i64
            ? $raw
            : $context->builder->sext($raw, $i64);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $rawI64, $zero);
        JitValueBox::writeBool($context, $slot, $isTrue);

        return JitValueBox::pointer($context, $slot);
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_GETLOGIN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_GETLOGIN,
            'posix_terminal_getlogin_bridge_entry',
            [],
            $strPtr,
            self::GETLOGIN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#6504'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TTYNAME,
            'posix_terminal_ttyname_bridge_entry',
            [$i64],
            $strPtr,
            self::TTYNAME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#6504'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ISATTY,
            'posix_terminal_isatty_bridge_entry',
            [$i64],
            $i64,
            self::ISATTY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#6504'
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
        foreach ([self::ABI_GETLOGIN, self::ABI_TTYNAME, self::ABI_ISATTY] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after PosixTerminalRuntime bridge (#6504)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
