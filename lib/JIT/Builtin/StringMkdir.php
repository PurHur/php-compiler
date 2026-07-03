<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for mkdir() via MkdirJitHelper PHP (#15586).
 *
 * Replaces inline warning LLVM in ext/standard/JitMkdir.php.
 * SSOT: {@see \PHPCompiler\ext\standard\MkdirJitHelper}.
 * php-src: ext/standard/filestat.c — php_mkdir
 */
final class StringMkdir
{
    private const ABI = '__phpc_jit_mkdir';

    private const HELPER_PATH = '/ext/standard/MkdirJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\MkdirJitHelper::invokeArgv';

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

    public static function invoke(Context $context, Value $path, Value $mode, Value $recursive): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $path,
            $mode,
            $recursive
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15586');

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $i64, $i1)
            );

        $entry = $fn->appendBasicBlock('mkdir_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#15586');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $bool = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($bool);

        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
