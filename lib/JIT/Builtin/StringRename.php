<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rename() (#15533, #19215, #20028, #20603, #29090, #29141).
 *
 * User-script AOT + embed: {@see RenameJitHelper} via {@see JitVmHelperLink}
 * (Unlink #19186 shape — helper SSOT is {@see \PHPCompiler\ext\standard\VmFs::rename()}).
 * NestedJIT leaf: module-local rename(2) decl (avoids re-entering the helper
 * bridge while NestedJIT compiles RenameJitHelper / VmFsPathPure `@rename`).
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

    /** Module-local rename(2) — NestedJIT leaf (#29090). */
    private const COMPILER_RENAME = '__compiler_rename';

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
        // Nested helper compile: libc leaf without re-entering RenameJitHelper (#29090).
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $from, $to);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $from, $to);
    }

    /** @return Value i1 — true when rename(2) returns 0 */
    public static function invokeNestedLeaf(Context $context, Value $fromStr, Value $toStr): Value
    {
        return self::invokeCompilerRename($context, $fromStr, $toStr);
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
            '#29141'
        );
    }

    /** @return Value i1 — true when rename(2) returns 0 */
    private static function invokeCompilerRename(Context $context, Value $fromStr, Value $toStr): Value
    {
        self::ensureCompilerRenameDecl($context);
        $map = $context->structFieldMap['__string__'];
        $fromPtr = $context->builder->structGep($fromStr, $map['value']);
        $toPtr = $context->builder->structGep($toStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction(self::COMPILER_RENAME),
            $fromPtr,
            $toPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    /**
     * Declare rename(2) under a compiler-owned alias so the shared libc extern table
     * can drop its rename row (#29090 / peer {@code __compiler_strtok_r} #29091).
     * The linker resolves the body from libc via the {@code rename} symbol name.
     */
    private static function ensureCompilerRenameDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_RENAME);

            return;
        } catch (\Throwable $e) {
        }
        // Prefer a direct libc rename symbol when already present (Module FS externs).
        try {
            $existing = $context->lookupFunction('rename');
            $context->registerFunction(self::COMPILER_RENAME, $existing);

            return;
        } catch (\Throwable $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        // Name the decl "rename" so the dynamic linker / AOT link finds libc rename(2).
        $fn = $context->module->addFunction('rename', $ft);
        $context->registerFunction('rename', $fn);
        $context->registerFunction(self::COMPILER_RENAME, $fn);
    }
}
