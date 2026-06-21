<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for scalar dim fetch warnings via ScalarDimFetchJitHelper PHP (#10271, #10343).
 *
 * SSOT: {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}, {@see \PHPCompiler\VM\ErrorReporter}
 */
final class ScalarDimFetchRuntime
{
    private const ABI_EMIT_WARNING = '__scalar_dim_fetch__emitWarning';

    private const HELPER_PATH = '/lib/VM/ScalarDimFetchJitHelper.php';

    private const EMIT_WARNING_HELPER = 'PHPCompiler\\VM\\ScalarDimFetchJitHelper::emitWarningForJitType';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EMIT_WARNING_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementEmitWarningBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitWarning(Context $context, int $jitType): void
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_EMIT_WARNING);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->call(
            $fn,
            $i8->constInt($jitType, false)
        );
    }

    private static function implementEmitWarningBridge(Context $context): void
    {
        $abiName = self::ABI_EMIT_WARNING;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('scalar_dim_fetch_warn_bridge_entry');
        $i64 = $context->getTypeFromString('int64');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::EMIT_WARNING_HELPER),
            $context->builder->zext($fn->getParam(0), $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ScalarDimFetchJitHelper compile (#10343)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ScalarDimFetchJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ScalarDimFetchJitHelper.php parseAndCompile failed (#10343)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10343)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $fn) {
            $context->registerFunction(self::ABI_EMIT_WARNING, $fn);
        }
    }
}
