<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_close() via SocketCloseJitHelper PHP (#27394).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SocketCreate / SocketImport #25211).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_close)
 */
final class SocketCloseRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketCloseJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketCloseJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::closeForHandle',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_socket_close',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_close');
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
        self::implementCloseBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementCloseBridge(Context $context): void
    {
        $abiName = '__compiler_socket_close';
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
                $context->context->functionType($voidTy, false, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_close_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::H.'::closeForHandle'),
            $fn->getParam(0)
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
                throw new \LogicException($name.' missing after SocketCloseRuntime link (#27394)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
