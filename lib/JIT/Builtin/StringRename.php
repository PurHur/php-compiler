<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitRenameKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rename() via phpc_rename_kernel + stat cache (#15533, #19215).
 *
 * User-script AOT uses {@see JitRenameKernel} libc rename(2) with stat invalidation;
 * {@see \PHPCompiler\ext\standard\RenameJitHelper} remains the php-in-PHP SSOT for
 * warnings/guards when nested-compiled (helper-runtime unit).
 * php-src: ext/standard/filestat.c — php_rename
 */
final class StringRename
{
    private const ABI = '__phpc_jit_rename';

    private const HELPER_PATH = '/ext/standard/RenameJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\RenameJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'rename_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $from, Value $to): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $from, $to);
    }

    private static function implement(Context $context): void
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

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#19215');
        StatCacheRuntime::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $okBlock = $fn->appendBasicBlock('rename_bridge_ok');
        $done = $fn->appendBasicBlock('rename_bridge_done');
        $context->builder->positionAtEnd($entry);

        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $ok = JitRenameKernel::invoke($context, $from, $to);
        $context->builder->branchIf($ok, $okBlock, $done);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $clearRealpath = $i64->constInt(1, false);
        $clearPathHelper = $context->functions['phpcompiler\\ext\\standard\\statcachejithelper::clearpath'] ?? null;
        if (null === $clearPathHelper) {
            throw new \LogicException('StatCacheJitHelper::clearPath missing for rename bridge (#19215)');
        }
        $context->builder->call($clearPathHelper, $clearRealpath, $from);
        $context->builder->call($clearPathHelper, $clearRealpath, $to);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $ret = $context->builder->phi($i1, 'rename_bridge_result');
        $ret->addIncoming($ok, $entry);
        $ret->addIncoming($ok, $okEnd);
        $context->builder->returnValue($ret);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
