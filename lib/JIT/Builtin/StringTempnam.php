<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmFsTempnam;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for tempnam() via TempnamJitHelper PHP (#15685).
 *
 * Replaces inline LLVM in ext/standard/JitTempnam.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFsTempnam}.
 * php-src: ext/standard/file.c — php_tempnam
 */
final class StringTempnam
{
    private const ABI = '__phpc_jit_tempnam';

    private const HELPER_PATH = '/ext/standard/TempnamJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\TempnamJitHelper::resolveArgv';

    private const NOTICE_HELPER = 'PHPCompiler\\ext\\standard\\TempnamJitHelper::consumeNotice';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
        self::NOTICE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $directory, Value $prefix): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $directory, $prefix);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        StringTriggerError::ensureLinked($context);
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15685');

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($valuePtr, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('tempnam_bridge_entry');
        $failBb = $fn->appendBasicBlock('tempnam_bridge_fail');
        $bodyBb = $fn->appendBasicBlock('tempnam_bridge_body');
        $context->builder->positionAtEnd($entry);

        $dir = $fn->getParam(0);
        $pfx = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dir, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $pfx, $strPtr->constNull())
        );
        $context->builder->branchIf($bad, $failBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#15685');
        $pathRaw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$dir, $pfx]);
        $pendingRaw = JitNestedHelperCoerce::callHelper(
            $context,
            JitVmHelperLink::lookupCompiled($context, self::NOTICE_HELPER, '#15685'),
            []
        );
        $i32 = $context->getTypeFromString('int32');
        $emitNotice = $context->builder->icmp(
            Builder::INT_NE,
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $pendingRaw, $i32),
            $i32->constInt(0, false)
        );
        $noticeDo = BasicBlockHelper::append($context, 'tempnam_bridge_notice_do');
        $afterNotice = BasicBlockHelper::append($context, 'tempnam_bridge_after_notice');
        $context->builder->branchIf($emitNotice, $noticeDo, $afterNotice);

        $context->builder->positionAtEnd($noticeDo);
        $message = VmFsTempnam::NOTICE_MESSAGE;
        $i8p = $context->getTypeFromString('int8*');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->branch($afterNotice);

        $context->builder->positionAtEnd($afterNotice);
        $pathNull = JitNestedHelperCoerce::isHelperResultNull($context, $pathRaw);
        $failResultBb = BasicBlockHelper::append($context, 'tempnam_bridge_result_fail');
        $okResultBb = BasicBlockHelper::append($context, 'tempnam_bridge_result_ok');
        $context->builder->branchIf($pathNull, $failResultBb, $okResultBb);

        $context->builder->positionAtEnd($failResultBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okResultBb);
        $pathStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $pathRaw);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $pathStr);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
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
