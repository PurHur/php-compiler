<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamModeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT ABI bridges for stream meta via StreamMetaJitHelper PHP (#6007, #13846, #19678, #22994).
 *
 * Quarantined from lib/JIT/Builtin/StreamMetaJit — {@see \PHPCompiler\JIT\Builtin\StreamMeta}
 * stays the thin orchestrator. Helper compile: {@see JitVmHelperLink::ensureCompiled}
 * (peer StreamBuffer #22979 / StreamMode #22968).
 *
 * Replaces ~424-line feof/fcntl/strncmp LLVM; SSOT {@see StreamMetaJitHelper}
 * php-src: ext/standard/streamsfuncs.c — stream_get_meta_data / stream_set_blocking
 */
final class JitStreamMetaKernel
{
    private const HELPER_PATH = '/ext/standard/StreamMetaJitHelper.php';

    private const GET_META_HELPER = 'PHPCompiler\\ext\\standard\\StreamMetaJitHelper::getMetaDataArgv';

    private const SET_BLOCKING_HELPER = 'PHPCompiler\\ext\\standard\\StreamMetaJitHelper::setBlockingArgv';

    private const ENABLE_CRYPTO_HELPER = 'PHPCompiler\\ext\\standard\\StreamMetaJitHelper::enableCryptoArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_META_HELPER,
        self::SET_BLOCKING_HELPER,
        self::ENABLE_CRYPTO_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_get_meta_data',
        '__compiler_stream_set_blocking',
        '__compiler_stream_enable_crypto',
    ];

    public static function implement(Context $context): void
    {
        // Thin AOT: LLVM meta from StreamGlobalsJit paths — NestedJIT VmFs misses those slots (#27659).
        if ($context->isThinStandaloneAotMain()) {
            self::implementThinStandalone($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_stream_get_meta_data');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        StreamModeRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_stream_get_meta_data', self::implementGetMetaBridge(...));
        self::implementIfMissing($context, '__compiler_stream_set_blocking', self::implementSetBlockingBridge(...));
        self::implementIfMissing($context, '__compiler_stream_enable_crypto', self::implementEnableCryptoBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Thin user-script AOT: path-table meta + NestedJIT set_blocking/enable_crypto (#27659). */
    private static function implementThinStandalone(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        JitStreamMetaThinAot::implementGetMetaData($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_stream_set_blocking', self::implementSetBlockingBridge(...));
        self::implementIfMissing($context, '__compiler_stream_enable_crypto', self::implementEnableCryptoBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
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
            '__compiler_stream_enable_crypto' => $context->context->functionType($i32, false, $i64, $i64, $i64, $i64),
            default => throw new \LogicException('JitStreamMetaKernel: unknown function '.$name),
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

    private static function implementEnableCryptoBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_enable_crypto_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ENABLE_CRYPTO_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $fn->getParam(3)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $result, $i32)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22994');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22994'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after JitStreamMetaKernel bridge (#13846)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
