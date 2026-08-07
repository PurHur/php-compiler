<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for shmop_* via ShmopJitHelper (#27408).
 *
 * Peer SocketCreateRuntime (#27394): NestedJIT compiles only the helper; libc FFI
 * stays in {@see \PHPCompiler\ext\sysvshm\ShmopLibcThinAbi} resolved on demand.
 * php-src: ext/shmop/shmop.c
 */
final class ShmopRuntime
{
    private const HELPER_PATH = '/ext/sysvshm/ShmopJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sysvshm\\ShmopJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::openArgv',
        self::H.'::pendingAddrArgv',
        self::H.'::pendingSizeArgv',
        self::H.'::pendingReadonlyArgv',
        self::H.'::registerOwnedArgv',
        self::H.'::sizeArgv',
        self::H.'::deleteArgv',
        self::H.'::closeArgv',
        self::H.'::readArgv',
        self::H.'::writeArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_shmop_open',
        '__compiler_shmop_pending_addr',
        '__compiler_shmop_pending_size',
        '__compiler_shmop_pending_readonly',
        '__compiler_shmop_register',
        '__compiler_shmop_size',
        '__compiler_shmop_delete',
        '__compiler_shmop_close',
        '__compiler_shmop_read',
        '__compiler_shmop_write',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_shmop_open');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementOpenBridge($context);
        self::implementPendingBridges($context);
        self::implementRegisterBridge($context);
        self::implementSizeBridge($context);
        self::implementDeleteBridge($context);
        self::implementCloseBridge($context);
        self::implementReadBridge($context);
        self::implementWriteBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementOpenBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_shmop_open',
            'shmop_open_entry',
            self::H.'::openArgv',
            4,
            true
        );
    }

    private static function implementPendingBridges(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_shmop_pending_addr',
            'shmop_pending_addr_entry',
            self::H.'::pendingAddrArgv',
            0,
            true
        );
        self::implementBridge(
            $context,
            '__compiler_shmop_pending_size',
            'shmop_pending_size_entry',
            self::H.'::pendingSizeArgv',
            0,
            true
        );
        self::implementBridge(
            $context,
            '__compiler_shmop_pending_readonly',
            'shmop_pending_readonly_entry',
            self::H.'::pendingReadonlyArgv',
            0,
            true
        );
    }

    private static function implementRegisterBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_shmop_register',
            'shmop_register_entry',
            self::H.'::registerOwnedArgv',
            5,
            false
        );
    }

    private static function implementSizeBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_shmop_size',
            'shmop_size_entry',
            self::H.'::sizeArgv',
            1,
            true
        );
    }

    private static function implementDeleteBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_shmop_delete',
            'shmop_delete_entry',
            self::H.'::deleteArgv',
            1,
            true
        );
    }

    private static function implementCloseBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_shmop_close',
            'shmop_close_entry',
            self::H.'::closeArgv',
            1,
            false
        );
    }

    private static function implementReadBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_read';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('shmop_read_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::readArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementWriteBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_write';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64)
            );

        $entry = $fn->appendBasicBlock('shmop_write_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::writeArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $entryName,
        string $helperLogical,
        int $argc,
        bool $returnsI64
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $retTy = $returnsI64 ? $i64 : $context->getTypeFromString('void');
        $paramTys = array_fill(0, $argc, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($retTy, false, ...$paramTys)
            );

        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0; $i < $argc; ++$i) {
            $args[] = $fn->getParam($i);
        }
        if ($returnsI64) {
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, $helperLogical),
                $args
            );
            $context->builder->returnValue(
                JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
            );
        } else {
            $context->builder->call(
                self::helperFunction($context, $helperLogical),
                ...$args
            );
            $context->builder->returnVoid();
        }
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27408');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27408'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ShmopRuntime link (#27408)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
