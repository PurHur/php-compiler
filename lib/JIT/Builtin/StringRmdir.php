<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rmdir() via RmdirJitHelper PHP (#15481).
 *
 * Replaces libc rmdir(2) LLVM in ext/standard/JitRmdir.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::rmdir()}.
 * php-src: ext/standard/filestat.c — php_rmdir
 */
final class StringRmdir
{
    private const ABI = '__phpc_jit_rmdir';

    private const HELPER_PATH = '/ext/standard/RmdirJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\RmdirJitHelper::invokeArgv';

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

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15481');

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('rmdir_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, '#15481');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$fn->getParam(0)]);
        $bool = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($bool);

        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
