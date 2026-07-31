<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_similar_text via SimilarTextJitHelper PHP (#9731, #25784).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Getrusage #25754 / PosixTimes #25600).
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

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25784');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25784'
        );
    }
}
