<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for chmod() via ChmodJitHelper PHP (#15458).
 *
 * Replaces libc chmod(2) LLVM in ext/standard/JitChmod.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::chmod()}.
 * php-src: ext/standard/filestat.c — php_chmod
 */
final class StringChmod
{
    private const ABI = '__phpc_jit_chmod';

    private const HELPER_PATH = '/ext/standard/ChmodJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ChmodJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $path, Value $mode): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path, $mode);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#19283 / #23346) — clearInsertionPosition
        // left the user-script builder detached ("Current basic block has no parent function").
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15458');

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $i32)
            );

        $entry = $fn->appendBasicBlock('chmod_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#15458');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$fn->getParam(0), $fn->getParam(1)]);
        $bool = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($bool);

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
