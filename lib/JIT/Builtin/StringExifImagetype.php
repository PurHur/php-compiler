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
 * JIT/AOT link for exif_imagetype() via ExifImagetypeJitHelper PHP (#18181).
 *
 * SSOT: {@see \PHPCompiler\ext\exif\ExifImagetypeJitHelper}.
 * php-src: ext/exif/exif.c — PHP_FUNCTION(exif_imagetype)
 */
final class StringExifImagetype
{
    private const ABI = '__phpc_jit_exif_imagetype';

    private const HELPER_PATH = '/ext/exif/ExifImagetypeJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\exif\\ExifImagetypeJitHelper::fromPath';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $pathStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $pathStr);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18181');

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($valuePtr, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('exif_imagetype_bridge_entry');
        $failBb = $fn->appendBasicBlock('exif_imagetype_bridge_fail');
        $okBb = $fn->appendBasicBlock('exif_imagetype_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#18181');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$fn->getParam(0)]);
        $i64 = $context->getTypeFromString('int64');
        $type = JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64);
        $failed = $context->builder->icmp(Builder::INT_SLT, $type, $i64->constInt(0, true));
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $failSlot, $i1->constInt(0, false));
        $context->builder->returnValue($failPtr);

        $context->builder->positionAtEnd($okBb);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        JitValueBox::writeLong($context, $okSlot, $type);
        $context->builder->returnValue($okPtr);

        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
