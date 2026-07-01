<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_explode via ExplodeJitHelper PHP (#14750).
 *
 * Replaces ~500 LOC inline LLVM in JitExplode.php runtime paths.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — php_explode()
 */
final class StringExplode
{
    private const ABI = 'phpc_explode';

    private const HELPER_PATH = '/ext/standard/ExplodeJitHelper.php';

    private const EXPLODE_HELPER = 'PHPCompiler\\ext\\standard\\ExplodeJitHelper::explodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXPLODE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $delimiter,
        Value $haystack,
        Value $limit
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $delimiter,
            $haystack,
            $limit
        );
    }

    private static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'explode_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $htPtr,
            self::EXPLODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14750'
        );
    }
}
