<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for unlink() via UnlinkJitHelper PHP (#15471, #19186).
 *
 * User-script AOT and embed route through helper-runtime + {@see UnlinkJitHelper} (#19157 pattern).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::unlink()}.
 * php-src: ext/standard/filestat.c — php_unlink
 */
final class StringUnlink
{
    private const ABI = '__phpc_jit_unlink';

    private const HELPER_PATH = '/ext/standard/UnlinkJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\UnlinkJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'unlink_bridge_entry';

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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $i1,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19186'
        );
    }
}
