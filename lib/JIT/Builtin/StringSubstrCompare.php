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
 * JIT/AOT link for substr_compare via SubstrCompareJitHelper PHP (#13536).
 *
 * Replaces ~289 LOC LLVM in StringSubstrCompareJit.php. Keeps i8* ABI for callers.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}
 */
final class StringSubstrCompare
{
    private const HELPER_PATH = '/ext/standard/SubstrCompareJitHelper.php';

    private const SUBSTR_COMPARE_HELPER = 'PHPCompiler\\ext\\standard\\SubstrCompareJitHelper::substrCompareArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SUBSTR_COMPARE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('substr_compare');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('substr_compare', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = 'substr_compare';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p, $i64, $i64, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('substr_compare_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $hayStr = self::stringFromCstr($context, $fn->getParam(0));
        $needleStr = self::stringFromCstr($context, $fn->getParam(1));
        $offset = $fn->getParam(2);
        $length = $fn->getParam(3);
        $caseInsensitive = $context->builder->icmp(
            Builder::INT_NE,
            $fn->getParam(4),
            $i32->constInt(0, false)
        );
        $raw = $context->builder->call(
            self::helperFunction($context, self::SUBSTR_COMPARE_HELPER),
            $hayStr,
            $needleStr,
            $offset,
            $length,
            $caseInsensitive
        );
        $context->builder->returnValue($context->builder->trunc($raw, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function stringFromCstr(Context $context, Value $cstr): Value
    {
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
            throw new \LogicException($logical.' missing after SubstrCompareJitHelper compile (#13536)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SubstrCompareJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SubstrCompareJitHelper.php parseAndCompile failed (#13536)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#13536)');
            }
        }
    }
}
