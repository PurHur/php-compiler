<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_readfile via ReadfileJitHelper PHP (#9188, #19966, #29915, #33021).
 *
 * Owns the ABI module-locally: {@see getNamedFunction} first, then {@see addFunction}
 * if absent. Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * readfile.1 (#31894 / #32122).
 * Always {@see JitVmHelperLink} → helper → `@readfile` → NestedJIT
 * whitelist {@see \PHPCompiler\ext\standard\readfile} → {@see JitReadfileLibc}.
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
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        // data:// via FileGetContentsJitHelper::readPathArgv NestedJIT (#34731).
        StringBase64Decode::ensureLinked($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [
                '/ext/standard/FileGetContentsJitHelper.php',
                self::HELPER_PATH,
            ],
            self::COMPILED_HELPERS,
            '#19966'
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        // Declare ABI module-locally when Type no longer always-on (#33021).
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i64, false, $strPtr)
            );
        $context->registerFunction(self::ABI, $fn);

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
            JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64)
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
}
