<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_utf8_strlen / __compiler_utf8_valid via Utf8JitHelper (#9246).
 */
final class StringUtf8Runtime
{
    private const HELPER_PATH = '/ext/standard/Utf8JitHelper.php';

    private const STRLEN_HELPER = 'PHPCompiler\\ext\\standard\\Utf8JitHelper::utf8CharLength';

    private const VALID_HELPER = 'PHPCompiler\\ext\\standard\\Utf8JitHelper::isValidUtf8';

    public static function ensureStrlenLinked(Context $context): void
    {
        self::implementBridge($context, '__compiler_utf8_strlen', self::STRLEN_HELPER, 0);
    }

    public static function ensureValidLinked(Context $context): void
    {
        self::implementBridge($context, '__compiler_utf8_valid', self::VALID_HELPER, 1);
    }

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $nullReturn
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
        self::emitBridge($context, $fn, self::helperFunction($context, $helperLogical), $nullReturn);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitBridge(
        Context $context,
        LlvmFunction $fn,
        LlvmFunction $helper,
        int $nullReturn
    ): void {
        $entry = $fn->appendBasicBlock('utf8_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $nullStr = $strPtr->constNull();
        $nullRet = $i64->constInt($nullReturn, false);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $input, $nullStr);
        $nullBb = $fn->appendBasicBlock('utf8_bridge_null');
        $workBb = $fn->appendBasicBlock('utf8_bridge_work');
        $context->builder->branchIf($isNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullRet);

        $context->builder->positionAtEnd($workBb);
        $result = $context->builder->call($helper, $input);
        $context->builder->returnValue($result);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after Utf8JitHelper compile (#9246)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $needed = [\strtolower(self::STRLEN_HELPER), \strtolower(self::VALID_HELPER)];
        $missing = false;
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'Utf8JitHelper.php');
        if (null === $block) {
            throw new \LogicException('Utf8JitHelper.php parseAndCompile failed (#9246)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9246)');
            }
        }
    }
}
