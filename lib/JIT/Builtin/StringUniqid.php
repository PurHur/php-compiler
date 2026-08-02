<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitDate;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_uniqid via UniqidJitHelper PHP (#14897, #26931).
 *
 * NestedJIT formats only (no host time/random in the helper TU — those OOM under
 * HELPER_RUNTIME_O=0). Wall sec via libc time(); usec from a process-local counter
 * (gettimeofday NestedJIT still BB-parent-broken — #26930); entropy u32 = sec^usec.
 *
 * php-src: ext/standard/uniqid.c — PHP_FUNCTION(uniqid)
 */
final class StringUniqid
{
    private const ABI_UNIQID = '__compiler_uniqid';

    private const HELPER_PATH = '/ext/standard/UniqidJitHelper.php';

    private const FORMAT_HELPER = 'PHPCompiler\\ext\\standard\\UniqidJitHelper::formatArgv';

    private const BRIDGE_ENTRY = 'uniqid_bridge_entry';

    private const USEC_MOD = 0x100000;

    private const USEC_COUNTER_GLOBAL = '__compiler_uniqid_usec_seq';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_UNIQID);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_UNIQID, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $probe = $context->module->getNamedFunction(self::ABI_UNIQID);

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26931'
        );

        LibcExtern::register($context);

        $ft = $context->context->functionType($strPtr, false, $strPtr, $i1);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI_UNIQID, $ft);
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $prefix = $fn->getParam(0);
        $moreEntropy = $fn->getParam(1);

        $sec = JitDate::time($context);
        if ($sec->typeOf() !== $i64) {
            $sec = $context->builder->zExt($sec, $i64);
        }
        $usec = self::nextUsecMasked($context);
        $entropyU32 = $context->builder->and(
            $context->builder->xor($sec, $usec),
            $i64->constInt(0xFFFFFFFF, false)
        );
        // NestedJIT int params are typically i64; more_entropy ABI is i1.
        $moreI64 = $context->builder->zExt($moreEntropy, $i64);

        $helper = self::helperFunction($context);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            $helper,
            [$prefix, $sec, $usec, $moreI64, $entropyU32]
        );
        $result = JitNestedHelperCoerce::coerceBridgeResult($context, $resultRaw, $strPtr);
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI_UNIQID, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * Process-local usec stand-in so consecutive uniqid() calls differ within a second
     * (php-src polls gettimeofday until usec changes).
     */
    private static function nextUsecMasked(Context $context): \PHPLLVM\Value
    {
        $i64 = $context->getTypeFromString('int64');
        $mod = $i64->constInt(self::USEC_MOD, false);
        $one = $i64->constInt(1, false);
        $existing = $context->module->getNamedGlobal(self::USEC_COUNTER_GLOBAL);
        if (null === $existing) {
            $existing = $context->module->addGlobal($i64, self::USEC_COUNTER_GLOBAL);
            $existing->setInitializer($i64->constInt(1, false));
        }
        $cur = $context->builder->load($existing);
        $next = $context->builder->add($cur, $one);
        $wrapped = $context->builder->unsigendRem($next, $mod);
        // Avoid 0 forever if rem yields 0 — bump to 1 (php-src usec is rarely 0 after poll).
        $isZero = $context->builder->icmp(Builder::INT_EQ, $wrapped, $i64->constInt(0, false));
        $usec = $context->builder->select($isZero, $one, $wrapped);
        $context->builder->store($next, $existing);

        return $usec;
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, self::FORMAT_HELPER, '#26931');
    }
}
