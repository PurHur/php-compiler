<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for spaceship (<=>) — CompareJitHelper PHP SSOT + LLVM dispatch (#9381).
 *
 * php-src: Zend/zend_operators.c; VM SSOT {@see \PHPCompiler\VM\Variable}.
 */
final class SpaceshipRuntime
{
    private const HELPER_PATH = '/lib/VM/CompareJitHelper.php';

    private const LONG_HELPER = 'PHPCompiler\\VM\\CompareJitHelper::longSpaceship';

    private const DOUBLE_HELPER = 'PHPCompiler\\VM\\CompareJitHelper::doubleSpaceship';

    private const STRING_HELPER = 'PHPCompiler\\VM\\CompareJitHelper::stringSpaceship';

    private const NUMBER_STRING_HELPER = 'PHPCompiler\\VM\\CompareJitHelper::spaceshipNumberString';

    private const KIND_HELPER = 'PHPCompiler\\VM\\CompareJitHelper::kindSpaceship';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LONG_HELPER,
        self::DOUBLE_HELPER,
        self::STRING_HELPER,
        self::NUMBER_STRING_HELPER,
        self::KIND_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);
        self::implement($context);
    }

    public static function callValueSpaceship(Context $context, Value $leftPtr, Value $rightPtr): Value
    {
        $fn = $context->lookupFunction('__value__spaceship');
        $params = $fn->typeOf()->getElementType()->getParameters();

        return $context->builder->call(
            $fn,
            $context->builder->pointerCast($leftPtr, $params[0]),
            $context->builder->pointerCast($rightPtr, $params[1])
        );
    }

    public static function callObjectCompareSpaceship(Context $context, Value $leftObj, Value $rightObj): Value
    {
        $fn = $context->lookupFunction('__object__compareSpaceship');
        $params = $fn->typeOf()->getElementType()->getParameters();

        return $context->builder->call(
            $fn,
            $context->builder->pointerCast($leftObj, $params[0]),
            $context->builder->pointerCast($rightObj, $params[1])
        );
    }

    public static function compareHelper(Context $context, string $logical): LlvmFunction
    {
        self::ensureCompareJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after CompareJitHelper compile (#9381)');
        }

        return $fn;
    }

    public static function implement(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureCompareJitHelperCompiled($context);
        SpaceshipCompareJit::implement($context);

        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function ensureCompareJitHelperCompiled(Context $context): void
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'CompareJitHelper.php');
        if (null === $block) {
            throw new \LogicException('CompareJitHelper.php parseAndCompile failed (#9381)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9381)');
            }
        }
    }
}
