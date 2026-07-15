<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for rename() via RenameJitHelper PHP (#15533, #19215).
 *
 * User-script AOT and embed route through helper-runtime + {@see RenameJitHelper} (#19186 pattern).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::rename()}.
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

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $fail = $fn->appendBasicBlock('rename_bridge_fail');
        $body = $fn->appendBasicBlock('rename_bridge_body');
        $context->builder->positionAtEnd($entry);

        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $from, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $to, $strPtr->constNull())
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#19215');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$from, $to]);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
