<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_stream_socket_pair via StreamSocketPairJitHelper PHP (#13710, #21082, #22468).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GzStreamRuntime #22431).
 * Embed + inventory/standalone AOT: always NestedJIT the same PHP bridge — no inventory
 * null-stub fork (StreamIo #20943 / StreamLifecycle #20966 shape). No libc socketpair LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\VmStreamSocketPairNative}
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_pair)
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_stream_socket_pair');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22468');
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

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22468'
        );
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
