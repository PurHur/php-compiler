<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for strrchr() via StrrchrJitHelper PHP (#15406).
 *
 * Replaces libc strrchr(3) LLVM in ext/standard/JitStrrchr.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::strrchr()}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrchr)
 */
final class StringStrrchr
{
    private const ABI = '__phpc_jit_strrchr';

    private const HELPER_PATH = '/ext/standard/StrrchrJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\StrrchrJitHelper::resolveArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $haystack, $needle);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15406');

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($valuePtr, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('strrchr_bridge_entry');
        $failBb = $fn->appendBasicBlock('strrchr_bridge_fail');
        $okBb = $fn->appendBasicBlock('strrchr_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $haystack = $fn->getParam(0);
        $needle = $fn->getParam(1);
        $nullHay = $context->builder->icmp(Builder::INT_EQ, $haystack, $strPtr->constNull());
        $nullNeedle = $context->builder->icmp(Builder::INT_EQ, $needle, $strPtr->constNull());
        $anyNull = $context->builder->or($nullHay, $nullNeedle);
        $context->builder->branchIf($anyNull, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#15406');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$haystack, $needle]);
        $isNullResult = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $failResultBb = $fn->appendBasicBlock('strrchr_bridge_result_fail');
        $okResultBb = $fn->appendBasicBlock('strrchr_bridge_result_ok');
        $context->builder->branchIf($isNullResult, $failResultBb, $okResultBb);

        $context->builder->positionAtEnd($failResultBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okResultBb);
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $resultStr
        );
        $context->builder->returnValue($ptr);

        $context->builder->positionAtEnd($failBb);
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $failSlot, $i1->constInt(0, false));
        $context->builder->returnValue($failPtr);

        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
