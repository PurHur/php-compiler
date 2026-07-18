<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitRenameKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rename() (#15533, #19215, #20028, #20603).
 *
 * Embed + thin standalone AOT: {@see RenameJitHelper} via {@see JitVmHelperLink}
 * (Unlink #19186 / HashEquals #20469 shape — no thin libc ABI fork).
 * Helper returns int 0/1 (coerced to i1); Nested helper compile uses {@see JitRenameKernel}.
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
        // Nested helper compile: libc leaf without re-entering RenameJitHelper (#17279 / MathFpow).
        if (NestedJitCompileScope::isActive()) {
            return JitRenameKernel::invoke($context, $from, $to);
        }

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

        $strPtr = $context->getTypeFromString('__string__*');
        // ABI stays i1 for rename() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603).
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $i1,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20603'
        );
    }
}
