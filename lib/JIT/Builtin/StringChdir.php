<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for chdir() (#21147, #26928, #29219).
 *
 * User-script AOT + embed: {@see ChdirJitHelper} via {@see JitVmHelperLink}
 * (Rename #29141 / Unlink #19186 shape).
 * NestedJIT leaf: module-local chdir(2) decl (avoids re-entering the helper
 * bridge while NestedJIT compiles ChdirJitHelper `@chdir`).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::chdir()}.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class StringChdir
{
    private const ABI = '__phpc_jit_chdir';

    private const HELPER_PATH = '/ext/standard/ChdirJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ChdirJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'chdir_bridge_entry';

    /** Module-local chdir(2) — NestedJIT leaf (#29219). */
    private const COMPILER_CHDIR = '__compiler_chdir';

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
        // Nested helper compile: libc leaf without re-entering ChdirJitHelper (#29219).
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $path);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    /** @return Value i1 — true when chdir(2) returns 0 */
    public static function invokeNestedLeaf(Context $context, Value $pathStr): Value
    {
        return self::invokeCompilerChdir($context, $pathStr);
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
        // ABI stays i1 for chdir() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603).
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
            '#29219'
        );
    }

    /** @return Value i1 — true when chdir(2) returns 0 */
    private static function invokeCompilerChdir(Context $context, Value $pathStr): Value
    {
        self::ensureCompilerChdirDecl($context);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction(self::COMPILER_CHDIR),
            $pathPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    /**
     * Declare chdir(2) under a compiler-owned alias so the shared libc extern table
     * can drop its chdir row (#29219 / peer {@code __compiler_rename} #29090).
     * The linker resolves the body from libc via the {@code chdir} symbol name.
     */
    private static function ensureCompilerChdirDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_CHDIR);

            return;
        } catch (\Throwable $e) {
        }
        // Prefer a direct libc chdir symbol when already present (Module FS externs).
        try {
            $existing = $context->lookupFunction('chdir');
            $context->registerFunction(self::COMPILER_CHDIR, $existing);

            return;
        } catch (\Throwable $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p);
        // Name the decl "chdir" so the dynamic linker / AOT link finds libc chdir(2).
        $fn = $context->module->addFunction('chdir', $ft);
        $context->registerFunction('chdir', $fn);
        $context->registerFunction(self::COMPILER_CHDIR, $fn);
    }
}
