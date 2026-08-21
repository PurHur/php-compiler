<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for symlink() via SymlinkJitHelper PHP (#15544, #33417).
 *
 * User-script AOT: {@see SymlinkJitHelper} via {@see JitVmHelperLink}
 * (Link #33406 shape — helper SSOT is {@see \PHPCompiler\ext\standard\VmFs::symlink()}).
 * NestedJIT leaf: module-local symlink(2) decl (avoids re-entering the helper
 * bridge while NestedJIT compiles SymlinkJitHelper / `@symlink`).
 * Replaces libc symlinkat(2) LLVM in ext/standard/JitSymlink.php.
 * php-src: ext/standard/link.c — php_symlink
 */
final class StringSymlink
{
    private const ABI = '__phpc_jit_symlink';

    private const HELPER_PATH = '/ext/standard/SymlinkJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\SymlinkJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'symlink_bridge_entry';

    /** Module-local symlink(2) — NestedJIT leaf (#33417 / peer link #33406). */
    private const COMPILER_SYMLINK = '__compiler_symlink';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $target, Value $link): Value
    {
        // Nested helper compile: libc leaf without re-entering SymlinkJitHelper (#33417).
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $target, $link);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $target, $link);
    }

    /** @return Value i1 — true when symlink(2) returns 0 */
    public static function invokeNestedLeaf(Context $context, Value $targetStr, Value $linkStr): Value
    {
        return self::invokeCompilerSymlink($context, $targetStr, $linkStr);
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
        // ABI stays i1 for symlink() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603 / #29141).
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
            '#33417'
        );
    }

    /** @return Value i1 — true when symlink(2) returns 0 */
    private static function invokeCompilerSymlink(Context $context, Value $targetStr, Value $linkStr): Value
    {
        self::ensureCompilerSymlinkDecl($context);
        $map = $context->structFieldMap['__string__'];
        $targetPtr = $context->builder->structGep($targetStr, $map['value']);
        $linkPtr = $context->builder->structGep($linkStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction(self::COMPILER_SYMLINK),
            $targetPtr,
            $linkPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    /**
     * Declare symlink(2) under a compiler-owned alias (peer {@see StringLink} #33406).
     * The linker resolves the body from libc via the {@code symlink} symbol name.
     */
    private static function ensureCompilerSymlinkDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_SYMLINK);

            return;
        } catch (\Throwable $e) {
        }
        try {
            $existing = $context->lookupFunction('symlink');
            $context->registerFunction(self::COMPILER_SYMLINK, $existing);

            return;
        } catch (\Throwable $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        $fn = $context->module->addFunction('symlink', $ft);
        $context->registerFunction('symlink', $fn);
        $context->registerFunction(self::COMPILER_SYMLINK, $fn);
    }
}
