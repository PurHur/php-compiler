<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ?-> receiver short-circuit via NullsafeJitHelper PHP (#10154).
 *
 * SSOT: {@see \PHPCompiler\VM\TypedPropertyCheck}, {@see \PHPCompiler\VM\NullsafeJitHelper}
 */
final class NullsafeRuntime
{
    private const HELPER_PATH = '/lib/VM/NullsafeJitHelper.php';

    private const VALUE_BOX_HELPER = 'PHPCompiler\\VM\\NullsafeJitHelper::valueBoxShortCircuits';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__nullsafe__valueBoxShortCircuits');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__nullsafe__valueBoxShortCircuits', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementValueBoxBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function callValueBoxShortCircuits(Context $context, Value $typeByte, Value $nullableSlot): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__nullsafe__valueBoxShortCircuits');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8),
            $context->builder->trunc($nullableSlot, $i1)
        );
    }

    private static function implementValueBoxBridge(Context $context): void
    {
        $abiName = '__nullsafe__valueBoxShortCircuits';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i8, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('nullsafe_value_box_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::VALUE_BOX_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
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
            throw new \LogicException($logical.' missing after NullsafeJitHelper compile (#10154)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'NullsafeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('NullsafeJitHelper.php parseAndCompile failed (#10154)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#10154)');
            }
        }
    }
}
