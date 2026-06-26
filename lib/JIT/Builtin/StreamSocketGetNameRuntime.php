<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_socket_get_name() via StreamSocketGetNameJitHelper (#12223).
 */
final class StreamSocketGetNameRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamSocketGetNameJitHelper.php';

    private const GET_NAME_HELPER = 'PHPCompiler\\ext\\standard\\StreamSocketGetNameJitHelper::getNameArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_NAME_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_socket_get_name');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_stream_socket_get_name', $probe);

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

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $name = '__compiler_stream_socket_get_name';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $name,
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
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamSocketGetNameJitHelper compile (#12223)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        if (isset($context->functions[\strtolower(self::GET_NAME_HELPER)])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamSocketGetNameJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('StreamSocketGetNameJitHelper.php parseAndCompile failed (#12223)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
            });
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        if (!isset($context->functions[\strtolower(self::GET_NAME_HELPER)])) {
            throw new \LogicException(self::GET_NAME_HELPER.' was not compiled for JIT (#12223)');
        }
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
