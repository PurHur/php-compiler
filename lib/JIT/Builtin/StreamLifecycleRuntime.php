<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed link for stream lifecycle ABI via StreamLifecycleJitHelper PHP (#9442).
 *
 * JIT embed and AOT standalone compile {@see StreamLifecycleJitHelper}; thin LLVM bridges forward the ABI.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs}, {@see \PHPCompiler\ext\standard\StreamLifecycleJitHelper}
 * php-src: ext/standard/streamsfuncs.c
 */
final class StreamLifecycleRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamLifecycleJitHelper.php';

    private const LIBC_HELPER_PATH = '/ext/standard/StreamLibcHandleJitHelper.php';

    private const IS_RESOURCE = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::isResourceArgv';

    private const FCLOSE = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::fcloseArgv';

    private const FEOF = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::feofArgv';

    private const FFLUSH = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::fflushArgv';

    private const PCLOSE = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::pcloseArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_RESOURCE,
        self::FCLOSE,
        self::FEOF,
        self::FFLUSH,
        self::PCLOSE,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_resource',
        '__compiler_fclose',
        '__compiler_feof',
        '__compiler_fflush',
        '__compiler_pclose',
    ];

    /** @var array<string, string> */
    private const ABI_TO_HELPER = [
        '__compiler_is_resource' => self::IS_RESOURCE,
        '__compiler_fclose' => self::FCLOSE,
        '__compiler_feof' => self::FEOF,
        '__compiler_fflush' => self::FFLUSH,
        '__compiler_pclose' => self::PCLOSE,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_is_resource');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::implementStandalone($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        foreach (self::ABI_TO_HELPER as $abi => $helper) {
            if ('__compiler_fclose' === $abi || '__compiler_pclose' === $abi) {
                self::implementCloseBridge($context, $abi, $helper);
                continue;
            }
            self::implementIfMissing($context, $abi, $helper);
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStandalone(Context $context): void
    {
        StreamGlobalsJit::implement($context);
        self::implementStandaloneIsResource($context);
        self::implementStandaloneFflush($context);
        self::implementStandaloneI32RetZero($context, '__compiler_fclose');
        self::implementStandaloneI32RetZero($context, '__compiler_feof');
        self::implementStandaloneI32RetZero($context, '__compiler_pclose');
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementStandaloneIsResource(Context $context): void
    {
        $abiName = '__compiler_is_resource';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock('is_resource_standalone_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementStandaloneFflush(Context $context): void
    {
        self::implementStandaloneI32RetZero($context, '__compiler_fflush');
    }

    private static function implementStandaloneI32RetZero(Context $context, string $abiName): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );
        $entry = $fn->appendBasicBlock('stream_lifecycle_standalone_zero');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementCloseBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock('stream_lifecycle_close_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $handleI32 = $context->builder->trunc($handle, $i32);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$handleI32]
        );
        StreamLibcHandleRuntime::emitClearLlvmHandleSlot($context, $handle);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementIfMissing(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock('stream_lifecycle_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $handleI32 = $context->builder->trunc($fn->getParam(0), $i32);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$handleI32]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamLifecycleJitHelper compile (#9442)');
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
        $root = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $root): void {
            foreach ([self::LIBC_HELPER_PATH, self::HELPER_PATH] as $rel) {
                $path = $root.$rel;
                $base = \basename($path);
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), $base);
                if (null === $block) {
                    throw new \LogicException($base.' parseAndCompile failed (#9442)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT stream lifecycle (#9442)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamLifecycleRuntime bridge (#9442)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
