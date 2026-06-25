<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT link hook for html_entity_decode() ENT_HTML5 (#4130). */
final class HtmlEntityDecodeJit
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntityDecodeJitHelper::decode';
    private const HELPER_ENCODING_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntityDecodeJitHelper::decodeWithEncoding';
    private const DISPATCH = '__compiler_html_entity_decode_dispatch';

    public static function decode(Context $context, Value $strPtr, Value $flags): Value
    {
        self::ensureDispatch($context);

        return $context->builder->call(
            $context->lookupFunction(self::DISPATCH),
            $strPtr,
            $flags
        );
    }

    public static function decodeWithEncoding(
        Context $context,
        Value $strPtr,
        Value $flags,
        Value $encodingPtr
    ): Value {
        self::ensureEncodingHelperCompiled($context);
        $lc = strtolower(self::HELPER_ENCODING_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('HtmlEntityDecodeJitHelper::decodeWithEncoding missing after compile (#11653)');
        }

        return $context->builder->call($fn, $strPtr, $flags, $encodingPtr);
    }

    private static function ensureDispatch(Context $context): void
    {
        self::ensureJitHelperCompiled($context);

        $wrapper = $context->module->getNamedFunction(self::DISPATCH);
        if (null !== $wrapper && $wrapper->countBasicBlocks() > 0) {
            $context->registerFunction(self::DISPATCH, $wrapper);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strType = $context->getTypeFromString('__string__*');
        $fnType = $context->context->functionType($strType, false, $strType, $i64);
        $wrapper = $context->module->addFunction(self::DISPATCH, $fnType);
        $context->registerFunction(self::DISPATCH, $wrapper);

        $fastFn = $context->lookupFunction('__string__htmlspecialchars_decode');
        $helperFn = self::helperFunction($context);
        $html5Mask = $i64->constInt(48, false);
        $zero = $i64->constInt(0, false);

        $entry = $wrapper->appendBasicBlock('entry');
        $fast = $wrapper->appendBasicBlock('fast');
        $slow = $wrapper->appendBasicBlock('slow');
        $merge = $wrapper->appendBasicBlock('merge');

        $context->builder->positionAtEnd($entry);
        $wStr = $wrapper->getParam(0);
        $wFlags = $wrapper->getParam(1);
        $hasHtml5 = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($wFlags, $html5Mask),
            $zero
        );
        $context->builder->branchIf($hasHtml5, $slow, $fast);

        $context->builder->positionAtEnd($fast);
        $fastVal = $context->builder->call($fastFn, $wStr, $wFlags);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($slow);
        $slowVal = $context->builder->call($helperFn, $wStr, $wFlags);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($strType);
        $phi->addIncoming($fastVal, $fast);
        $phi->addIncoming($slowVal, $slow);
        $context->builder->returnValue($phi);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_LOGICAL);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('HtmlEntityDecodeJitHelper::decode missing after compile (#4130)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = strtolower(self::HELPER_LOGICAL);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = dirname(__DIR__, 3).'/ext/standard/HtmlEntityDecodeJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'HtmlEntityDecodeJitHelper.php');
        if (null === $block) {
            throw new \LogicException('HtmlEntityDecodeJitHelper.php parseAndCompile failed (#4130)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('HtmlEntityDecodeJitHelper::decode was not compiled for JIT (#4130)');
        }
    }

    private static function ensureEncodingHelperCompiled(Context $context): void
    {
        $lc = strtolower(self::HELPER_ENCODING_LOGICAL);
        if (isset($context->functions[$lc])) {
            return;
        }
        self::ensureJitHelperCompiled($context);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('HtmlEntityDecodeJitHelper::decodeWithEncoding was not compiled for JIT (#11653)');
        }
    }
}
