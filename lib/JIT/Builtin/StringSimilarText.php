<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_similar_text via SimilarTextJitHelper PHP (#9731).
 *
 * Replaces former ~420-line LLVM Oliver algorithm with thin char* bridges into {@see VmString} SSOT.
 * php-src: ext/standard/string.c — php_similar_text, PHP_FUNCTION(similar_text)
 */
final class StringSimilarText
{
    private const HELPER_PATH = '/ext/standard/SimilarTextJitHelper.php';

    private const COMPUTE_HELPER = 'PHPCompiler\\ext\\standard\\SimilarTextJitHelper::compute';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPUTE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_similar_text');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_similar_text', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = 'phpc_similar_text';
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $charPtr, $charPtr);
        $fn = $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('similar_text_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $s1 = self::cstrToString($context, $fn->getParam(0));
        $s2 = self::cstrToString($context, $fn->getParam(1));
        $result = $context->builder->call(
            self::helperFunction($context, self::COMPUTE_HELPER),
            $s1,
            $s2
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $empty = $charPtr->constNull();
        $use = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $cstr, $empty),
            $empty,
            $cstr
        );
        $len = $context->builder->call($context->lookupFunction('strlen'), $use);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $use
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SimilarTextJitHelper compile (#9731)');
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SimilarTextJitHelper.php');
        if (null === $block) {
            throw new \LogicException('SimilarTextJitHelper.php parseAndCompile failed (#9731)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9731)');
            }
        }
    }
}
