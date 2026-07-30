<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_socket_accept() via StreamSocketAcceptJitHelper (#15346, #25183).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StreamSocketGetName #24850 / StreamPath #25139).
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_accept)
 */
final class StreamSocketAcceptRuntime
{
    private const ABI_NAME = '__compiler_stream_socket_accept';

    private const HELPER_PATH = '/ext/standard/StreamSocketAcceptJitHelper.php';

    private const ACCEPT_HELPER = 'PHPCompiler\\ext\\standard\\StreamSocketAcceptJitHelper::acceptArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ACCEPT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
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
        self::implementBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_NAME,
                $context->context->functionType($i64, false, $i64, $i64, $double)
            );

        $entry = $fn->appendBasicBlock('stream_socket_accept_entry');
        $context->builder->positionAtEnd($entry);

        $serverHandle = $fn->getParam(0);
        $hasTimeout = $fn->getParam(1);
        $timeout = $fn->getParam(2);
        $handleRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ACCEPT_HELPER),
            [$serverHandle, $hasTimeout, $timeout]
        );
        $handle = JitNestedHelperCoerce::coerceBridgeResult($context, $handleRaw, $i64);
        $context->builder->returnValue($handle);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25183');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25183'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after StreamSocketAcceptRuntime bridge (#15346)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
