<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_stream_path via StreamPathJitHelper PHP (#9480).
 *
 * Replaces LLVM phpc_stream_paths[] table lookup; SSOT {@see \PHPCompiler\ext\standard\VmFs}.
 * php-src: ext/standard/streams.c — php_stream path metadata
 */
final class StreamPathRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamPathJitHelper.php';

    private const PATH_HELPER = 'PHPCompiler\\ext\\standard\\StreamPathJitHelper::pathForHandle';

    private const REGISTER_HELPER = 'PHPCompiler\\ext\\standard\\StreamPathJitHelper::register';

    private const CLEAR_HELPER = 'PHPCompiler\\ext\\standard\\StreamPathJitHelper::clear';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PATH_HELPER,
        self::REGISTER_HELPER,
        self::CLEAR_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_stream_path',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stream_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementPathBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitRegisterPath(Context $context, Value $handle, Value $pathStr): void
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::REGISTER_HELPER),
            $context->builder->truncOrBitCast($handle, $context->getTypeFromString('int64')),
            $pathStr
        );
    }

    public static function emitClearPath(Context $context, Value $handle): void
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::CLEAR_HELPER),
            $context->builder->truncOrBitCast($handle, $context->getTypeFromString('int64'))
        );
    }

    private static function implementPathBridge(Context $context): void
    {
        $abiName = '__phpc_stream_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('stream_path_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::PATH_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamPathJitHelper compile (#9480)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamPathJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamPathJitHelper.php parseAndCompile failed (#9480)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9480)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamPathRuntime bridge (#9480)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
