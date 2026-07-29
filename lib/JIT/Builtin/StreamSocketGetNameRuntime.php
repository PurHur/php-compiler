<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_socket_get_name() via StreamSocketGetNameJitHelper (#12223, #24850).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GetHeaders #24633 / SocketAtmark #24831).
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_name)
 */
final class StreamSocketGetNameRuntime
{
    private const ABI_NAME = '__compiler_stream_socket_get_name';

    private const HELPER_PATH = '/ext/standard/StreamSocketGetNameJitHelper.php';

    private const GET_NAME_HELPER = 'PHPCompiler\\ext\\standard\\StreamSocketGetNameJitHelper::getNameArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_NAME_HELPER,
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

        self::ensureExternStringInit($context);
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
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_NAME,
                $context->context->functionType($strPtr, false, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('stream_socket_get_name_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $wantPeer = $fn->getParam(1);
        $textRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GET_NAME_HELPER),
            [$handle, $wantPeer]
        );
        $text = JitNestedHelperCoerce::coerceBridgeResult($context, $textRaw, $strPtr);
        $context->builder->returnValue($text);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24850');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24850'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after StreamSocketGetNameRuntime bridge (#12223)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function ensureExternStringInit(Context $context): void
    {
        try {
            $context->lookupFunction('__string__init');
        } catch (\Throwable) {
            $i64 = $context->getTypeFromString('int64');
            $charPtr = $context->getTypeFromString('char*');
            $strPtr = $context->getTypeFromString('__string__*');
            $fn = $context->module->addFunction(
                '__string__init',
                $context->context->functionType($strPtr, false, $i64, $charPtr)
            );
            $context->registerFunction('__string__init', $fn);
        }
    }
}
