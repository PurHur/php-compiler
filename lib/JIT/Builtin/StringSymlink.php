<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for symlink() via SymlinkJitHelper PHP.
 *
 * Replaces libc symlinkat(2) LLVM in ext/standard/JitSymlink.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::symlink()}.
 * php-src: ext/standard/filestat.c — php_symlink
 */
final class StringSymlink
{
    private const ABI = '__phpc_jit_symlink';

    private const HELPER_PATH = '/ext/standard/SymlinkJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\SymlinkJitHelper::invokeArgv';

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

    public static function invoke(Context $context, Value $target, Value $link): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $target, $link);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#19283 / #26323) — clearInsertionPosition
        // left the user-script builder detached ("Current basic block has no parent function").
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, 'symlink-jit-php');

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('symlink_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, 'symlink-jit-php');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$fn->getParam(0), $fn->getParam(1)]);
        // NestedJIT may box bool as __value__; coerceHelperScalarResult leaves it as always-false (#20652 / #26323).
        $bool = JitNestedHelperCoerce::extractBoolFromHelperResult($context, $raw);
        $context->builder->returnValue($bool);

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
