<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_stream_socket_pair via StreamSocketPairJitHelper PHP (#13710).
 *
 * Embed and standalone AOT compile the same PHP bridge; no libc socketpair LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\VmStreamSocketPairNative}
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_socket_pair)
 */
final class StreamSocketPairRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamSocketPairJitHelper.php';

    public const PAIR_HELPER = 'PHPCompiler\\ext\\standard\\StreamSocketPairJitHelper::pairArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PAIR_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_socket_pair',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_socket_pair');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (self::shouldDeferInventoryEmit($context)) {
            self::implementStub($context);
            self::registerLinkedRuntime($context);
            $context->builder->clearInsertionPosition();

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StreamIoJit::ensureStreamGlobals($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_stream_socket_pair', self::implementPairBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamSocketPairJitHelper compile (#13710)');
        }

        return $fn;
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
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($htPtr, false, $i64, $i64, $i64)
        );
    }

    private static function implementPairBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_socket_pair_entry');
        $fail = $fn->appendBasicBlock('stream_socket_pair_fail');
        $body = $fn->appendBasicBlock('stream_socket_pair_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $domain = $context->builder->trunc($fn->getParam(0), $i32);
        $type = $context->builder->trunc($fn->getParam(1), $i32);
        $protocol = $context->builder->trunc($fn->getParam(2), $i32);
        $context->builder->branch($body);

        $context->builder->positionAtEnd($body);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PAIR_HELPER),
            [$domain, $type, $protocol]
        );
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $retBb = $fn->appendBasicBlock('stream_socket_pair_ret');
        $context->builder->branchIf($htNull, $fail, $retBb);

        $context->builder->positionAtEnd($retBb);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($htPtr->constNull());
    }

    private static function implementStub(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_socket_pair');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_stream_socket_pair', $probe);

            return;
        }

        $fn = self::declareFunction($context, '__compiler_stream_socket_pair');
        $entry = $fn->appendBasicBlock('stream_socket_pair_stub_entry');
        $context->builder->positionAtEnd($entry);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $context->builder->returnValue($htPtr->constNull());
        $context->registerFunction('__compiler_stream_socket_pair', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function shouldDeferInventoryEmit(Context $context): bool
    {
        unset($context);
        foreach (['PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', 'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER'] as $key) {
            $flag = getenv($key);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }

        return false;
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamSocketPairJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamSocketPairJitHelper.php parseAndCompile failed (#13710)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT stream_socket_pair (#13710)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamSocketPairRuntime bridge (#13710)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
