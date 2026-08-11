<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmFnmatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fnmatch() (#30383, #3189).
 *
 * User-script AOT + embed: {@see FnmatchJitHelper} via {@see JitVmHelperLink}
 * (chdir #29219 / rename #29141 shape).
 * NestedJIT leaf: module-local fnmatch(3) decl (avoids re-entering the helper
 * bridge while NestedJIT compiles FnmatchJitHelper `@fnmatch`).
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmFnmatchPure}.
 * php-src: ext/standard/fnmatch.c — PHP_FUNCTION(fnmatch)
 */
final class StringFnmatch
{
    private const ABI = '__phpc_jit_fnmatch';

    private const HELPER_PATH = '/ext/standard/FnmatchJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\FnmatchJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'fnmatch_bridge_entry';

    /** Module-local fnmatch(3) — NestedJIT leaf (#30383). */
    private const COMPILER_FNMATCH = '__compiler_fnmatch';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * @param Value $pattern `__string__*`
     * @param Value $filename `__string__*`
     * @param Value $flagsI32 PHP FNM_* bits as i32
     *
     * @return Value i1 — true when pattern matches
     */
    public static function invoke(Context $context, Value $pattern, Value $filename, Value $flagsI32): Value
    {
        // Nested helper compile: libc leaf without re-entering FnmatchJitHelper (#30383).
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $pattern, $filename, $flagsI32);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $pattern,
            $filename,
            $flagsI32
        );
    }

    /** @return Value i1 — true when fnmatch(3) returns 0 */
    public static function invokeNestedLeaf(
        Context $context,
        Value $patternStr,
        Value $filenameStr,
        Value $flagsI32
    ): Value {
        return self::invokeCompilerFnmatch($context, $patternStr, $filenameStr, $flagsI32);
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
        $i32 = $context->getTypeFromString('int32');
        // ABI stays i1 for fnmatch() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603).
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $strPtr, $i32],
            $i1,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30383'
        );
    }

    /** @return Value i1 — true when fnmatch(3) returns 0 */
    private static function invokeCompilerFnmatch(
        Context $context,
        Value $patternStr,
        Value $filenameStr,
        Value $flagsI32
    ): Value {
        self::ensureCompilerFnmatchDecl($context);
        $map = $context->structFieldMap['__string__'];
        $patternPtr = $context->builder->structGep($patternStr, $map['value']);
        $filenamePtr = $context->builder->structGep($filenameStr, $map['value']);
        $sysFlags = self::phpFlagsToSystem($context, $flagsI32);
        $i32 = $context->getTypeFromString('int32');
        $rc = $context->builder->call(
            $context->lookupFunction(self::COMPILER_FNMATCH),
            $patternPtr,
            $filenamePtr,
            $sysFlags
        );

        return $context->builder->icmp(
            Builder::INT_EQ,
            $rc,
            $i32->constInt(0, false)
        );
    }

    /** Map PHP FNM_* bits to libc fnmatch(3) flags (php-src ext/standard/fnmatch.c). */
    private static function phpFlagsToSystem(Context $context, Value $phpFlags): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $sys = $i32->constInt(0, false);
        /** @var array<int, int> $map */
        $map = [
            VmFnmatch::FNM_NOESCAPE => VmFnmatch::FNM_NOESCAPE,
            VmFnmatch::FNM_PATHNAME => VmFnmatch::FNM_PATHNAME,
            VmFnmatch::FNM_PERIOD => VmFnmatch::FNM_PERIOD,
            VmFnmatch::FNM_CASEFOLD => VmFnmatch::FNM_CASEFOLD,
        ];
        foreach ($map as $phpBit => $sysBit) {
            $masked = $context->builder->and($phpFlags, $i32->constInt($phpBit, false));
            $hasBit = $context->builder->icmp(Builder::INT_NE, $masked, $i32->constInt(0, false));
            $orVal = $context->builder->or($sys, $i32->constInt($sysBit, false));
            $sys = $context->builder->select($hasBit, $orVal, $sys);
        }

        return $sys;
    }

    /**
     * Declare fnmatch(3) under a compiler-owned alias so Module jitInit can drop its
     * always-on fnmatch row (#30383 / peer {@code __compiler_chdir} #29219).
     * The linker resolves the body from libc via the {@code fnmatch} symbol name.
     */
    private static function ensureCompilerFnmatchDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_FNMATCH);

            return;
        } catch (\Throwable $e) {
        }
        // Prefer a direct libc fnmatch symbol when already present (legacy Module decls).
        try {
            $existing = $context->lookupFunction('fnmatch');
            $context->registerFunction(self::COMPILER_FNMATCH, $existing);

            return;
        } catch (\Throwable $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p, $i32);
        // Name the decl "fnmatch" so the dynamic linker / AOT link finds libc fnmatch(3).
        $fn = $context->module->addFunction('fnmatch', $ft);
        $context->registerFunction('fnmatch', $fn);
        $context->registerFunction(self::COMPILER_FNMATCH, $fn);
    }
}
