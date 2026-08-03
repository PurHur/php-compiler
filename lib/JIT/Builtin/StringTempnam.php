<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitTempnamKernel;
use PHPCompiler\ext\standard\VmFsTempnam;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for tempnam() via TempnamJitHelper PHP (#15685, #27089).
 *
 * Thin standalone AOT: libc mkstemp via {@see JitTempnamKernel} (NestedJIT host-fopen
 * cannot create files — peer SysGetTempDirRuntime #26929 / InetRuntime #27088).
 * Embed: NestedJIT TempnamJitHelper + FsDirJitHelper.
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmFsTempnam}.
 * php-src: ext/standard/file.c — php_tempnam
 */
final class StringTempnam
{
    private const ABI = '__phpc_jit_tempnam';

    private const HELPER_PATH = '/ext/standard/TempnamJitHelper.php';

    private const FS_DIR_HELPER_PATH = '/ext/standard/FsDirJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\TempnamJitHelper::resolveArgv';

    private const NOTICE_HELPER = 'PHPCompiler\\ext\\standard\\TempnamJitHelper::consumeNotice';

    /** @var list<string> */
    private const HELPER_BUNDLE = [
        self::FS_DIR_HELPER_PATH,
        self::HELPER_PATH,
    ];

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

        // Preserve caller insert — NestedJIT / kernel emit must not orphan mid-emit (#27089 / #27088).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        if ($context->isThinStandaloneAotMain()) {
            JitTempnamKernel::implementForThinAot($context);
        } else {
            StringTriggerError::ensureLinked($context);
            JitVmHelperLink::ensureCompiledBundle(
                $context,
                self::HELPER_BUNDLE,
                self::COMPILED_HELPERS,
                '#27089'
            );

            $strPtr = $context->getTypeFromString('__string__*');
            $valuePtr = $context->getTypeFromString('__value__*');
            $fn = null !== $probe
                ? $probe
                : $context->module->addFunction(
                    self::ABI,
                    $context->context->functionType($valuePtr, false, $strPtr, $strPtr)
                );

            self::emitNestedBridge($context, $fn);
            $context->registerFunction(self::ABI, $fn);
        }

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitNestedBridge(Context $context, LlvmFunction $fn): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');

        $entry = $fn->appendBasicBlock('tempnam_bridge_entry');
        $failBb = $fn->appendBasicBlock('tempnam_bridge_fail');
        $bodyBb = $fn->appendBasicBlock('tempnam_bridge_body');
        $noticeDo = $fn->appendBasicBlock('tempnam_bridge_notice_do');
        $afterNotice = $fn->appendBasicBlock('tempnam_bridge_after_notice');
        $failResultBb = $fn->appendBasicBlock('tempnam_bridge_result_fail');
        $okResultBb = $fn->appendBasicBlock('tempnam_bridge_result_ok');

        $context->builder->positionAtEnd($entry);

        $dir = $fn->getParam(0);
        $pfx = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dir, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $pfx, $strPtr->constNull())
        );
        $context->builder->branchIf($bad, $failBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#27089');
        $pathRaw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$dir, $pfx]);
        $pendingRaw = JitNestedHelperCoerce::callHelper(
            $context,
            JitVmHelperLink::lookupCompiled($context, self::NOTICE_HELPER, '#27089'),
            []
        );
        $emitNotice = $context->builder->icmp(
            Builder::INT_NE,
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $pendingRaw, $i32),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($emitNotice, $noticeDo, $afterNotice);

        $context->builder->positionAtEnd($noticeDo);
        $message = VmFsTempnam::NOTICE_MESSAGE;
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
        JitValueBox::writeBool($context, $failSlot, $i1->constInt(0, false));
        $context->builder->returnValue($failPtr);
    }
}
