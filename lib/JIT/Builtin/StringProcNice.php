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
 * JIT/AOT link for proc_nice() (#30615, #5181).
 *
 * User-script AOT + embed: {@see ProcNiceJitHelper} via {@see JitVmHelperLink}
 * (chroot #30558 / chdir #29219 shape).
 * NestedJIT leaf: module-local nice(3) + __errno_location (avoids re-entering the
 * helper bridge while NestedJIT compiles ProcNiceJitHelper `@proc_nice`).
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmProcNicePure}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(proc_nice)
 */
final class StringProcNice
{
    private const ABI = '__phpc_jit_proc_nice';

    private const HELPER_PATH = '/ext/standard/ProcNiceJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ProcNiceJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'proc_nice_bridge_entry';

    /** Module-local nice(3) — NestedJIT leaf (#30615). */
    private const COMPILER_NICE = '__compiler_nice';

    private static int $blockSerial = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * @param Value $priorityI64 zend long priority delta
     *
     * @return Value i1 — true when nice succeeds
     */
    public static function invoke(Context $context, Value $priorityI64): Value
    {
        // Nested helper compile: libc leaf without re-entering ProcNiceJitHelper (#30615).
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $priorityI64);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $priorityI64);
    }

    /** @return Value i1 — true when nice(3) succeeds */
    public static function invokeNestedLeaf(Context $context, Value $priorityI64): Value
    {
        return self::invokeCompilerNice($context, $priorityI64);
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

        $i64 = $context->getTypeFromString('int64');
        // ABI stays i1 for proc_nice() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603).
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$i64],
            $i1,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30615'
        );
    }

    /** @return Value i1 — true when nice(3) succeeds (errno-aware, peer former JitProcNice). */
    private static function invokeCompilerNice(Context $context, Value $priorityI64): Value
    {
        self::ensureCompilerNiceDecl($context);

        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $minusOne = $i32->constInt(-1, false);

        $priorityI32 = $priorityI64->typeOf() === $i32
            ? $priorityI64
            : $context->builder->trunc($priorityI64, $i32);

        $errnoPtr = $context->builder->call($context->lookupFunction('__errno_location'));
        $context->builder->store($zeroI32, $errnoPtr);

        $ret = $context->builder->call(
            $context->lookupFunction(self::COMPILER_NICE),
            $priorityI32
        );
        $retI32 = $ret->typeOf() === $i32 ? $ret : $context->builder->trunc($ret, $i32);
        $errnoVal = $context->builder->load($errnoPtr);

        $id = (string) (++self::$blockSerial);
        $checkErrnoBlock = BasicBlockHelper::append($context, 'proc_nice_check_errno_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'proc_nice_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'proc_nice_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'proc_nice_done_'.$id);

        $isMinusOne = $context->builder->icmp(Builder::INT_EQ, $retI32, $minusOne);
        $context->builder->branchIf($isMinusOne, $checkErrnoBlock, $okBlock);

        $context->builder->positionAtEnd($checkErrnoBlock);
        $hasErrno = $context->builder->icmp(Builder::INT_NE, $errnoVal, $zeroI32);
        $context->builder->branchIf($hasErrno, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i1 = $context->getTypeFromString('int1');
        $ok = $context->builder->phi($i1);
        $ok->addIncoming($i1->constInt(0, false), $failBlock);
        $ok->addIncoming($i1->constInt(1, false), $okBlock);

        return $ok;
    }

    /**
     * Declare nice(3) under a compiler-owned alias so Module.php can keep its
     * always-on nice decl dropped (#30530 / #30615).
     * The linker resolves the body from libc via the {@code nice} symbol name.
     */
    private static function ensureCompilerNiceDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_NICE);
        } catch (\Throwable $e) {
            try {
                $existing = $context->lookupFunction('nice');
                $context->registerFunction(self::COMPILER_NICE, $existing);
            } catch (\Throwable $e2) {
                $i32 = $context->getTypeFromString('int32');
                $fn = $context->module->addFunction(
                    'nice',
                    $context->context->functionType($i32, false, $i32)
                );
                $context->registerFunction('nice', $fn);
                $context->registerFunction(self::COMPILER_NICE, $fn);
            }
        }

        try {
            $context->lookupFunction('__errno_location');
        } catch (\Throwable $e) {
            $i32 = $context->getTypeFromString('int32');
            $i32Ptr = $i32->pointerType(0);
            $fn = $context->module->addFunction(
                '__errno_location',
                $context->context->functionType($i32Ptr, false)
            );
            $context->registerFunction('__errno_location', $fn);
        }
    }
}
