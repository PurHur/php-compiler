<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_create() via SocketCreateJitHelper PHP (#27394).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SocketImport #25211).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_create)
 */
final class SocketCreateRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketCreateJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketCreateJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::createFdArgv',
        self::H.'::registerOwnedArgv',
        // Same NestedJIT unit as #27423 — compile pair/io too if create runs first.
        self::H.'::fdForHandleArgv',
        self::H.'::writeArgv',
        self::H.'::readArgv',
        self::H.'::readFailedArgv',
        self::H.'::markReadFailedArgv',
        self::H.'::clearReadFailedArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_socket_create_fd',
        '__compiler_socket_create_register',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_create_fd');
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
        self::implementCreateFdBridge($context);
        self::implementRegisterBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementCreateFdBridge(Context $context): void
    {
        $abiName = '__compiler_socket_create_fd';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_create_fd_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::createFdArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRegisterBridge(Context $context): void
    {
        $abiName = '__compiler_socket_create_register';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($voidTy, false, $i64, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_create_register_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::H.'::registerOwnedArgv'),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27394');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27394'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketCreateRuntime link (#27394)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
