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
 * JIT/AOT link for __compiler_resolve_sidecar_source_path via ResolveSidecarJitHelper PHP (#11412).
 *
 * Replaces {@see StringFsDirJit::emitResolveSidecarSourcePath} libc access/getenv/snprintf LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\ResolveSidecarJitHelper}, {@see \PHPCompiler\JIT\SidecarPathRemap}
 */
final class ResolveSidecarRuntime
{
    private const HELPER_PATH = '/ext/standard/ResolveSidecarJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\ResolveSidecarJitHelper::resolveArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_resolve_sidecar_source_path',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_resolve_sidecar_source_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_resolve_sidecar_source_path', self::implementResolveBridge(...));
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

        $strPtr = $context->getTypeFromString('__string__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false, $strPtr)
        );
    }

    private static function implementResolveBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('resolve_sidecar_bridge_entry');
        $fail = $fn->appendBasicBlock('resolve_sidecar_bridge_fail');
        $body = $fn->appendBasicBlock('resolve_sidecar_bridge_body');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $path = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $resolvedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::RESOLVE_HELPER),
            [$path]
        );
        $resolved = JitNestedHelperCoerce::coerceBridgeResult($context, $resolvedRaw, $strPtr);
        $context->builder->returnValue($resolved);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ResolveSidecarJitHelper compile (#11412)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ResolveSidecarJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ResolveSidecarJitHelper.php parseAndCompile failed (#11412)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT resolve sidecar (#11412)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ResolveSidecarRuntime bridge (#11412)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
