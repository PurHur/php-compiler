<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for chroot() (#30558, #3500).
 *
 * User-script AOT + embed: {@see ChrootJitHelper} via {@see JitVmHelperLink}
 * (chdir #29219 / Rename #29141 shape).
 * NestedJIT leaf: module-local chroot(2) decl (avoids re-entering the helper
 * bridge while NestedJIT compiles ChrootJitHelper `@chroot`).
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmChrootPure}.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chroot)
 */
final class StringChroot
{
    private const ABI = '__phpc_jit_chroot';

    private const HELPER_PATH = '/ext/standard/ChrootJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ChrootJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'chroot_bridge_entry';

    /** Module-local chroot(2) — NestedJIT leaf (#30558). */
    private const COMPILER_CHROOT = '__compiler_chroot';

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
        // Nested helper compile: libc leaf without re-entering ChrootJitHelper (#30558).
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $path);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    /** @return Value i1 — true when chroot(2) returns 0 */
    public static function invokeNestedLeaf(Context $context, Value $pathStr): Value
    {
        return self::invokeCompilerChroot($context, $pathStr);
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
        // ABI stays i1 for chroot() callers; helper returns int 0/1 so NestedJIT
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
            '#30558'
        );
    }

    /** @return Value i1 — true when chroot(2) returns 0 */
    private static function invokeCompilerChroot(Context $context, Value $pathStr): Value
    {
        self::ensureCompilerChrootDecl($context);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction(self::COMPILER_CHROOT),
            $pathPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    /**
     * Declare chroot(2) under a compiler-owned alias so Module.php can drop its
     * always-on chroot decl (#30558 / peer {@code __compiler_chdir} #29219).
     * The linker resolves the body from libc via the {@code chroot} symbol name.
     */
    private static function ensureCompilerChrootDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_CHROOT);

            return;
        } catch (\Throwable $e) {
        }
        // Prefer a direct libc chroot symbol when already present (legacy Module FS externs).
        try {
            $existing = $context->lookupFunction('chroot');
            $context->registerFunction(self::COMPILER_CHROOT, $existing);

            return;
        } catch (\Throwable $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p);
        // Name the decl "chroot" so the dynamic linker / AOT link finds libc chroot(2).
        $fn = $context->module->addFunction('chroot', $ft);
        $context->registerFunction('chroot', $fn);
        $context->registerFunction(self::COMPILER_CHROOT, $fn);
    }
}
