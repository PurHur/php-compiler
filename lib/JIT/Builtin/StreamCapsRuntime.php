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
 * JIT/AOT link for __compiler_stream_is_local_uri via StreamCapsJitHelper PHP (#11413).
 *
 * Replaces {@see StreamCapsJit::emitIsLocalUri} URL-scheme LLVM walk.
 * SSOT: {@see \PHPCompiler\ext\standard\VmStreamMeta::isLocalUri()}
 * php-src: ext/standard/streamsfuncs.c
 */
final class StreamCapsRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamCapsJitHelper.php';

    private const IS_LOCAL_URI_HELPER = 'PHPCompiler\\ext\\standard\\StreamCapsJitHelper::isLocalUriArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_LOCAL_URI_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_is_local_uri',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_is_local_uri');
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
        self::implementIfMissing($context, '__compiler_stream_is_local_uri', self::implementIsLocalUriBridge(...));
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

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $i8p)
        );
    }

    private static function implementIsLocalUriBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_is_local_uri_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_is_local_uri_bridge_fail');
        $body = $fn->appendBasicBlock('stream_is_local_uri_bridge_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $path = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $i8p->constNull());
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $len = $context->builder->call($context->lookupFunction('strlen'), $path);
        $uriObj = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zext($len, $i64),
            $path
        );
        $hitRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::IS_LOCAL_URI_HELPER),
            [$uriObj]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $hitRaw, $i32)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamCapsJitHelper compile (#11413)');
        }

        return $fn;
    }

    private static function ensureExternStringInit(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamCapsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamCapsJitHelper.php parseAndCompile failed (#11413)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT stream caps (#11413)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamCapsRuntime bridge (#11413)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
