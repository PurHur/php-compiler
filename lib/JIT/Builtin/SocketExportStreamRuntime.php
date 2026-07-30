<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_export_stream() via SocketExportStreamJitHelper PHP (#6349, #25211).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SocketAtmark #24831 / StreamSocketAccept #25183).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_export_stream)
 */
final class SocketExportStreamRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketExportStreamJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketExportStreamJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::streamHandleForSocket',
        self::H.'::warnUnableToExport',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_socket_export_warn',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_export_warn');
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
        self::implementWarnBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function streamHandleHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::H.'::streamHandleForSocket', '#25211');
    }

    private static function implementWarnBridge(Context $context): void
    {
        $abiName = '__compiler_socket_export_warn';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($voidTy, false)
            );

        $entry = $fn->appendBasicBlock('socket_export_warn_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::H.'::warnUnableToExport')
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25211');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25211'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketExportStreamRuntime link (#6349)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
