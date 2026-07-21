<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSpaceshipCompareKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for spaceship (<=>) — CompareJitHelperScalars NestedJIT + LLVM object/ht (#9381, #21109).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SessionCreateIdRuntime #21941).
 * php-src: Zend/zend_operators.c; VM SSOT {@see \PHPCompiler\VM\Variable}.
 */
final class SpaceshipRuntime
{
    private const HELPER_PATH = '/lib/VM/CompareJitHelperScalars.php';

    private const LONG_HELPER = 'PHPCompiler\\VM\\CompareJitHelperScalars::longSpaceship';

    private const DOUBLE_HELPER = 'PHPCompiler\\VM\\CompareJitHelperScalars::doubleSpaceship';

    private const STRING_HELPER = 'PHPCompiler\\VM\\CompareJitHelperScalars::stringSpaceship';

    private const NUMBER_STRING_HELPER = 'PHPCompiler\\VM\\CompareJitHelperScalars::spaceshipNumberString';

    private const KIND_HELPER = 'PHPCompiler\\VM\\CompareJitHelperScalars::kindSpaceship';

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
        // Do not eager-link GC here (#21109): NestedJIT GC/NativeOps mid-spaceship
        // compile pollutes the module with [8 x i8] icmp / bitcast verify failures.
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
            throw new \LogicException($logical.' missing after CompareJitHelperScalars compile (#9381/#21109)');
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

        JitSpaceshipCompareKernel::declareAbiFunctions($context);
        self::ensureCompareJitHelperCompiled($context);
        JitSpaceshipCompareKernel::implement($context);

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

        // ABI decls before NestedJIT so CompareJitHelperScalars can resolve externs (#9381/#21109).
        JitSpaceshipCompareKernel::declareAbiFunctions($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21949'
        );
    }
}
