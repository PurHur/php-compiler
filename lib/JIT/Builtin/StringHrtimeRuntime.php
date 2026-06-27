<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hrtime_ns / __compiler_hrtime_pair via HrtimeJitHelper (#9182).
 */
final class StringHrtimeRuntime
{
    private const HELPER_PATH = '/ext/standard/HrtimeJitHelper.php';

    private const PAIR_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::pair';

    private const NS_FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::nsFloat';

    private const NS_INT_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::nsInt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PAIR_HELPER,
        self::NS_FLOAT_HELPER,
        self::NS_INT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementNsBridge($context);
        self::implementPairBridge($context);
    }

    private static function implementPairBridge(Context $context): void
    {
        self::implementZeroArgBridge($context, '__compiler_hrtime_pair', self::PAIR_HELPER);
    }

    private static function implementNsBridge(Context $context): void
    {
        $abiName = '__compiler_hrtime_ns';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        if (CompilerVersion::supportsHrtimeAsNumberFloat()) {
            $doubleTy = $context->getTypeFromString('double');
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($doubleTy, false)
                );
            $entry = $fn->appendBasicBlock('hrtime_ns_entry');
            $context->builder->positionAtEnd($entry);
            $result = $context->builder->call(self::helperFunction($context, self::NS_FLOAT_HELPER));
        } else {
            $i64 = $context->getTypeFromString('int64');
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($i64, false)
                );
            $entry = $fn->appendBasicBlock('hrtime_ns_entry');
            $context->builder->positionAtEnd($entry);
            $result = $context->builder->call(self::helperFunction($context, self::NS_INT_HELPER));
        }
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementZeroArgBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction($abiName);

        $entry = $fn->appendBasicBlock('hrtime_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $result = JitNestedHelperCoerce::coerceToHashtablePtr($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after HrtimeJitHelper compile (#9182)');
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
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'HrtimeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HrtimeJitHelper.php parseAndCompile failed (#9182)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9182)');
            }
        }
    }
}
