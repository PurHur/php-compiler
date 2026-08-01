<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link hook for html_entity_decode() ENT_HTML5 (#4130, #26441).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer HtmlEntitiesJit #26417).
 */
final class HtmlEntityDecodeJit
{
    private const HELPER_PATH = '/ext/standard/HtmlEntityDecodeJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntityDecodeJitHelper::decode';

    private const HELPER_ENCODING_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntityDecodeJitHelper::decodeWithEncoding';

    private const DISPATCH = '__compiler_html_entity_decode_dispatch';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
        self::HELPER_ENCODING_LOGICAL,
    ];

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
        self::ensureJitHelperCompiled($context);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER_ENCODING_LOGICAL, '#26441');

        return $context->builder->call($helperFn, $strPtr, $flags, $encodingPtr);
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
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER_LOGICAL, '#26441');
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

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26441'
        );
    }
}
