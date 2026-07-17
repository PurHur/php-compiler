<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitReadfileLibc;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_readfile via ReadfileJitHelper PHP (#9188, #19966).
 *
 * When JIT modules are registered: {@see ReadfileJitHelper} → {@see phpc_readfile_kernel}.
 * During Context construction (modules empty, #15417): thin {@see JitReadfileLibc} body so
 * user-script AOT does not ExternalMethod-stub the nested helper (#16075).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::readfile()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_passthru
 */
final class StringReadfile
{
    private const ABI = '__compiler_readfile';

    private const HELPER_PATH = '/ext/standard/ReadfileJitHelper.php';

    private const READFILE_HELPER = 'PHPCompiler\\ext\\standard\\ReadfileJitHelper::readfile';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::READFILE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'readfile_bridge_entry';

    private const LIBC_ENTRY = 'rf_libc_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::LIBC_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // User-script thin standalone: modules may still be empty during Context
        // construction (#15417); emit libc passthrough (former defer kernel body).
        if ($context->isThinStandaloneAotMain()) {
            self::implementLibcBody($context, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#19966');

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction(self::ABI);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $failBb = $fn->appendBasicBlock('readfile_bridge_fail');
        $okBb = $fn->appendBasicBlock('readfile_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $isNullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNullPath, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::READFILE_HELPER, '#19966');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$path]);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i64->constInt(-1, false));

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementLibcBody(Context $context, $probe): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::register($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i64, false, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::LIBC_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitReadfileLibc::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

}
