<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream meta ABI via StreamMetaJitHelper PHP (#6007, #13846).
 *
 * Replaces ~424-line feof/fcntl/strncmp LLVM; SSOT {@see \PHPCompiler\ext\standard\StreamMetaJitHelper}
 * php-src: ext/standard/streams.c — stream_get_meta_data / stream_set_blocking
 */
final class StreamMetaJit
{
    private const HELPER_PATH = '/ext/standard/StreamMetaJitHelper.php';

    private const GET_META_HELPER = 'PHPCompiler\\ext\\standard\\StreamMetaJitHelper::getMetaDataArgv';

    private const SET_BLOCKING_HELPER = 'PHPCompiler\\ext\\standard\\StreamMetaJitHelper::setBlockingArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_META_HELPER,
        self::SET_BLOCKING_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_get_meta_data',
        '__compiler_stream_set_blocking',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_get_meta_data');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StreamModeRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_stream_get_meta_data', self::implementGetMetaBridge(...));
        self::implementIfMissing($context, '__compiler_stream_set_blocking', self::implementSetBlockingBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = match ($name) {
            '__compiler_stream_get_meta_data' => $context->context->functionType($htPtr, false, $i64),
            '__compiler_stream_set_blocking' => $context->context->functionType($i32, false, $i64, $i64),
            default => throw new \LogicException('StreamMetaJit: unknown function '.$name),
        };

        return $context->module->addFunction($name, $ft);
    }

    private static function implementGetMetaBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_meta_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_meta_bridge_fail');
        $body = $fn->appendBasicBlock('stream_meta_bridge_body');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $handle = $fn->getParam(0);
        $badHandle = $context->builder->icmp(
            Builder::INT_SLE,
            $handle,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $context->builder->branchIf($badHandle, $fail, $body);

        $context->builder->positionAtEnd($body);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GET_META_HELPER),
            [$handle]
        );
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $retBb = $fn->appendBasicBlock('stream_meta_bridge_ret');
        $context->builder->branchIf($htNull, $fail, $retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue(JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($htPtr->constNull());
    }

    private static function implementSetBlockingBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_blocking_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::SET_BLOCKING_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $result, $i32)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamMetaJitHelper compile (#13846)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamMetaJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamMetaJitHelper.php parseAndCompile failed (#13846)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#13846)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamMetaJit bridge (#13846)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
