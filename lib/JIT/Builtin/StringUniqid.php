<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_uniqid via UniqidJitHelper PHP (#14897).
 *
 * Replaces ~143 LOC inline LLVM in JitUniqid.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/uniqid.c — PHP_FUNCTION(uniqid)
 */
final class StringUniqid
{
    private const ABI_UNIQID = '__compiler_uniqid';

    private const HELPER_PATH = '/ext/standard/UniqidJitHelper.php';

    private const UNIQID_HELPER = 'PHPCompiler\\ext\\standard\\UniqidJitHelper::uniqidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::UNIQID_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_UNIQID,
            'uniqid_bridge_entry',
            [$strPtr, $i1],
            $strPtr,
            self::UNIQID_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14897'
        );
    }
}
