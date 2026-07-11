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
 * JIT/AOT link for phpc_levenshtein via LevenshteinJitHelper PHP (#14648).
 *
 * Replaces ~196-line LLVM DP in ext/standard/JitLevenshtein.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/levenshtein.c — PHP_FUNCTION(levenshtein)
 */
final class StringLevenshtein
{
    private const HELPER_PATH = '/ext/standard/LevenshteinJitHelper.php';

    private const COMPUTE_HELPER = 'PHPCompiler\\ext\\standard\\LevenshteinJitHelper::computeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPUTE_HELPER,
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('phpc_levenshtein');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_levenshtein', $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        $context->registerFunction(
            'phpc_levenshtein',
            $context->module->getNamedFunction('phpc_levenshtein')
                ?? throw new \LogicException('phpc_levenshtein missing after StringLevenshtein bridge (#14648)')
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = 'phpc_levenshtein';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i64, false, $strPtr, $strPtr, $i64, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('levenshtein_bridge_entry');
        $fail = $fn->appendBasicBlock('levenshtein_bridge_fail');
        $body = $fn->appendBasicBlock('levenshtein_bridge_body');
        $context->builder->positionAtEnd($entry);

        $s1 = $fn->getParam(0);
        $s2 = $fn->getParam(1);
        $s1Null = $context->builder->icmp(Builder::INT_EQ, $s1, $strPtr->constNull());
        $s2Null = $context->builder->icmp(Builder::INT_EQ, $s2, $strPtr->constNull());
        $bad = $context->builder->or($s1Null, $s2Null);
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COMPUTE_HELPER),
            [
                $s1,
                $s2,
                $context->builder->trunc($fn->getParam(2), $i32),
                $context->builder->trunc($fn->getParam(3), $i32),
                $context->builder->trunc($fn->getParam(4), $i32),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after LevenshteinJitHelper compile (#14648)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'LevenshteinJitHelper.php');
            if (null === $block) {
                throw new \LogicException('LevenshteinJitHelper.php parseAndCompile failed (#14648)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#14648)');
            }
        }
    }
}
