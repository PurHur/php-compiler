<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream_socket_accept() via StreamSocketAcceptJitHelper (#15346).
 */
final class StreamSocketAcceptRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamSocketAcceptJitHelper.php';

    private const ACCEPT_HELPER = 'PHPCompiler\\ext\\standard\\StreamSocketAcceptJitHelper::acceptArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ACCEPT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_socket_accept');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_stream_socket_accept', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

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
        $name = '__compiler_stream_socket_accept';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $name,
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
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamSocketAcceptJitHelper compile (#15346)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        if (isset($context->functions[\strtolower(self::ACCEPT_HELPER)])) {
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
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamSocketAcceptJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('StreamSocketAcceptJitHelper.php parseAndCompile failed (#15346)');
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
        if (!isset($context->functions[\strtolower(self::ACCEPT_HELPER)])) {
            throw new \LogicException(self::ACCEPT_HELPER.' was not compiled for JIT (#15346)');
        }
    }
}
