<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for getcwd() via GetcwdJitHelper PHP (#29429, #26928, #25541).
 *
 * Embed + thin standalone AOT: {@see GetcwdJitHelper} via {@see JitVmHelperLink}
 * (chdir #29219 / gethostname #29364 / microtime #29405 shape — no always-on libc fork).
 * Nested helper compile: `@getcwd` → thin getcwd(2) leaf without re-entering
 * GetcwdJitHelper (former always-on realpath(".") LLVM #26928).
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmFs::getcwd()} / {@see VmGetcwdNative}.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(getcwd)
 */
final class GetcwdJit
{
    private const ABI = '__phpc_jit_getcwd';

    private const HELPER_PATH = '/ext/standard/GetcwdJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\GetcwdJitHelper::resolveJit';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'getcwd_bridge_entry';

    /** Module-local getcwd(2) — NestedJIT leaf (#29429). */
    private const COMPILER_GETCWD = '__compiler_getcwd';

    private const PATH_MAX = 4096;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value `__string__*` — empty string when cwd unavailable */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI));
    }

    /** @return Value `__string__*` — empty string when getcwd(2) fails */
    public static function invokeNestedLeaf(Context $context): Value
    {
        self::ensureCompilerGetcwdDecl($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $nullPtr = $i8p->constNull();

        $buf = BasicBlockHelper::entryAlloca(
            $context,
            $i8->arrayType(self::PATH_MAX),
            'getcwd_buf'
        );
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $resolved = $context->builder->call(
            $context->lookupFunction(self::COMPILER_GETCWD),
            $bufPtr,
            $i64->constInt(self::PATH_MAX, false)
        );

        $failBb = BasicBlockHelper::append($context, 'getcwd_fail');
        $okBb = BasicBlockHelper::append($context, 'getcwd_ok');
        $doneBb = BasicBlockHelper::append($context, 'getcwd_done');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $resolved, $nullPtr);
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $charPtr)
        );
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $resolved);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($resolved, $charPtr)
        );
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr, 'getcwd_str');
        $phi->addIncoming($empty, $failEnd);
        $phi->addIncoming($str, $okEnd);

        return $phi;
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [],
            $strPtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29429'
        );
    }

    /**
     * Declare getcwd(2) under a compiler-owned alias so NestedJIT does not re-enter
     * the helper bridge (#29429 / peer {@code __compiler_chdir} #29219).
     */
    private static function ensureCompilerGetcwdDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_GETCWD);

            return;
        } catch (\Throwable $e) {
        }
        try {
            $existing = $context->lookupFunction('getcwd');
            $context->registerFunction(self::COMPILER_GETCWD, $existing);

            return;
        } catch (\Throwable $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i8p, false, $i8p, $i64);
        // Name the decl "getcwd" so the dynamic linker / AOT link finds libc getcwd(2).
        $fn = $context->module->addFunction('getcwd', $ft);
        $context->registerFunction('getcwd', $fn);
        $context->registerFunction(self::COMPILER_GETCWD, $fn);

        // strlen for NestedJIT leaf path length
        try {
            $context->lookupFunction('strlen');
        } catch (\Throwable $e) {
            $strlenFt = $context->context->functionType($i64, false, $i8p);
            $strlenFn = $context->module->addFunction('strlen', $strlenFt);
            $context->registerFunction('strlen', $strlenFn);
        }
    }
}
