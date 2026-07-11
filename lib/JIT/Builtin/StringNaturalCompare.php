<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for strnatcmp/strnatcasecmp via NaturalCompareJitHelper PHP (#13535).
 *
 * Replaces ~365 LOC LLVM in StringNaturalCompareJit.php. Keeps i8* ABI for HashTable sort.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}
 */
final class StringNaturalCompare
{
    private const HELPER_PATH = '/ext/standard/NaturalCompareJitHelper.php';

    private const STRNATCMP_HELPER = 'PHPCompiler\\ext\\standard\\NaturalCompareJitHelper::strnatcmpArgv';

    private const STRNATCASECMP_HELPER = 'PHPCompiler\\ext\\standard\\NaturalCompareJitHelper::strnatcasecmpArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRNATCMP_HELPER,
        self::STRNATCASECMP_HELPER,
    ];

    public static function ensureStrnatcmpLinked(Context $context): void
    {
        self::implementNamed($context, 'strnatcmp', self::STRNATCMP_HELPER);
    }

    public static function ensureStrnatcasecmpLinked(Context $context): void
    {
        self::implementNamed($context, 'strnatcasecmp', self::STRNATCASECMP_HELPER);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureStrnatcmpLinked($context);
        self::ensureStrnatcasecmpLinked($context);
    }

    private static function implementNamed(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context, $abiName, $helperLogical);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('natcmp_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $aStr = self::stringFromCstr($context, $fn->getParam(0));
        $bStr = self::stringFromCstr($context, $fn->getParam(1));
        $raw = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $aStr,
            $bStr
        );
        $context->builder->returnValue($context->builder->trunc($raw, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function stringFromCstr(Context $context, Value $cstr): Value {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $null = $i8p->constNull();

        $emptySlot = BasicBlockHelper::entryAlloca($context, $i8);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $cstr, $null);
        $ptr = $context->builder->select($isNull, $emptyPtr, $cstr);
        $len = $context->builder->call($context->lookupFunction('strlen'), $ptr);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $ptr
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after NaturalCompareJitHelper compile (#13535)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'NaturalCompareJitHelper.php');
            if (null === $block) {
                throw new \LogicException('NaturalCompareJitHelper.php parseAndCompile failed (#13535)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#13535)');
            }
        }
    }
}
