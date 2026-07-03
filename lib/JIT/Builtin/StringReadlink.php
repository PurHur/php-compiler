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
 * JIT/AOT link for readlink() via ReadlinkJitHelper PHP (#15353).
 *
 * Replaces libc readlink(2)/__string__init LLVM in ext/standard/JitReadlink.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::readlink()}.
 * php-src: ext/standard/filestat.c — php_readlink
 */
final class StringReadlink
{
    private const ABI = '__phpc_jit_readlink';

    private const HELPER_PATH = '/ext/standard/ReadlinkJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\ReadlinkJitHelper::resolveArgv';

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

    public static function invoke(Context $context, Value $path): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15353');

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($valuePtr, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('readlink_bridge_entry');
        $failBb = $fn->appendBasicBlock('readlink_bridge_fail');
        $okBb = $fn->appendBasicBlock('readlink_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $isNullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNullPath, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#15353');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$path]);
        $isNullResult = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $failResultBb = $fn->appendBasicBlock('readlink_bridge_result_fail');
        $okResultBb = $fn->appendBasicBlock('readlink_bridge_result_ok');
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
