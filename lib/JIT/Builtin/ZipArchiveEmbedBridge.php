<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile link for ZipArchiveJitHelper (#35424 leftover of #6414).
 *
 * Single NestedJIT string-returning {@see exec} — packs LE int32 + optional payload.
 */
final class ZipArchiveEmbedBridge
{
    private const HELPER_PATH = '/ext/zip/ZipArchiveJitHelper.php';

    private const EXEC = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::exec';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXEC,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function execHelper(): string
    {
        return self::EXEC;
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#35424');
    }

    public static function opString(Context $context, string $op): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $bytes = $context->builder->pointerCast(
            $context->constantFromString($op),
            $context->getTypeFromString('char*')
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(strlen($op), false),
            $bytes
        );
    }

    public static function emptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $i64->constInt(0, false)
        );
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35424'
        );
    }
}
