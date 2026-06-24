<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_base_convert / phpc_basetozval_result via MathBaseConvertJitHelper PHP (#9584).
 *
 * Replaces {@see MathBaseConvertJit} LLVM (~950 LOC). SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c
 */
final class MathBaseConvertRuntime
{
    private const HELPER_PATH = '/ext/standard/MathBaseConvertJitHelper.php';

    private const BASE_CONVERT = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::baseConvert';

    private const PARSE_BASE_TO_ZVAL = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::parseBaseToZval';

    private const LAST_LONG = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::lastLong';

    private const LAST_DOUBLE = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::lastDouble';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BASE_CONVERT,
        self::PARSE_BASE_TO_ZVAL,
        self::LAST_LONG,
        self::LAST_DOUBLE,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        'phpc_base_convert',
        'phpc_basetozval_result',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_base_convert');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_base_convert', self::implementBaseConvertBridge(...));
        self::implementIfMissing($context, 'phpc_basetozval_result', self::implementBaseToZvalResultBridge(...));
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i64Ptr = $context->getTypeFromString('int64*');
        $doublePtr = $context->getTypeFromString('double*');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        return match ($name) {
            'phpc_base_convert' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p, $i64, $i64)
            ),
            'phpc_basetozval_result' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i64, $i64Ptr, $doublePtr)
            ),
            default => throw new \LogicException('Unknown base_convert JIT helper: '.$name),
        };
    }

    private static function implementBaseConvertBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('mbc_convert_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $from = $context->builder->trunc($fn->getParam(1), $context->getTypeFromString('int32'));
        $to = $context->builder->trunc($fn->getParam(2), $context->getTypeFromString('int32'));
        $fromI64 = $context->builder->sext($from, $i64);
        $toI64 = $context->builder->sext($to, $i64);

        $result = $context->builder->call(
            self::helperFunction($context, self::BASE_CONVERT),
            $fn->getParam(0),
            $fromI64,
            $toI64
        );
        $context->builder->returnValue($result);
    }

    private static function implementBaseToZvalResultBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('mbc_btz_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $base = $context->builder->trunc($fn->getParam(1), $i32);
        $baseI64 = $context->builder->sext($base, $i64);

        $tag = $context->builder->call(
            self::helperFunction($context, self::PARSE_BASE_TO_ZVAL),
            $fn->getParam(0),
            $baseI64
        );

        $outLong = $fn->getParam(2);
        $outDouble = $fn->getParam(3);
        $nullLong = $context->builder->icmp(
            Builder::INT_EQ,
            $outLong,
            $i64->pointerType(0)->constNull()
        );
        $nullDbl = $context->builder->icmp(
            Builder::INT_EQ,
            $outDouble,
            $double->pointerType(0)->constNull()
        );

        $isDouble = $context->builder->icmp(
            Builder::INT_NE,
            $tag,
            $i32->constInt(0, false)
        );
        $fetchLong = BasicBlockHelper::append($context, 'mbc_btz_fetch_long');
        $fetchDouble = BasicBlockHelper::append($context, 'mbc_btz_fetch_double');
        $afterFetch = BasicBlockHelper::append($context, 'mbc_btz_after_fetch');
        $context->builder->branchIf($isDouble, $fetchDouble, $fetchLong);

        $context->builder->positionAtEnd($fetchLong);
        $longI64 = $context->builder->sext(
            $context->builder->call(self::helperFunction($context, self::LAST_LONG)),
            $i64
        );
        $context->builder->branch($afterFetch);

        $context->builder->positionAtEnd($fetchDouble);
        $dblVal = $context->builder->call(self::helperFunction($context, self::LAST_DOUBLE));
        $context->builder->branch($afterFetch);

        $context->builder->positionAtEnd($afterFetch);
        $longPhi = $context->builder->phi($i64, 'mbc_btz_long_phi');
        $dblPhi = $context->builder->phi($double, 'mbc_btz_dbl_phi');
        $longPhi->addIncoming($longI64, $fetchLong);
        $longPhi->addIncoming($i64->constInt(0, false), $fetchDouble);
        $dblPhi->addIncoming($double->constFloat(0.0), $fetchLong);
        $dblPhi->addIncoming($dblVal, $fetchDouble);

        $storeLong = BasicBlockHelper::append($context, 'mbc_btz_store_long');
        $skipLong = BasicBlockHelper::append($context, 'mbc_btz_skip_long');
        $storeDbl = BasicBlockHelper::append($context, 'mbc_btz_store_dbl');
        $done = BasicBlockHelper::append($context, 'mbc_btz_done');
        $context->builder->branchIf($nullLong, $skipLong, $storeLong);

        $context->builder->positionAtEnd($storeLong);
        $context->builder->store($longPhi, $outLong);
        $context->builder->branch($skipLong);

        $context->builder->positionAtEnd($skipLong);
        $context->builder->branchIf($nullDbl, $done, $storeDbl);

        $context->builder->positionAtEnd($storeDbl);
        $context->builder->store($dblPhi, $outDouble);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($tag);
    }

    public static function baseToZvalCall(Context $context, $strDataPtr, int $base)
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $longOut = BasicBlockHelper::entryAlloca($context, $i64);
        $doubleOut = BasicBlockHelper::entryAlloca($context, $double);
        $isDouble = $context->builder->call(
            $context->lookupFunction('phpc_basetozval_result'),
            $strDataPtr,
            $i64->constInt($base, false),
            $longOut,
            $doubleOut
        );
        $isDoubleFlag = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->trunc($isDouble, $i32),
            $i32->constInt(0, false)
        );

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $longBb = BasicBlockHelper::append($context, 'basetozval_long');
        $doubleBb = BasicBlockHelper::append($context, 'basetozval_double');
        $doneBb = BasicBlockHelper::append($context, 'basetozval_done');
        $context->builder->branchIf($isDoubleFlag, $doubleBb, $longBb);

        $context->builder->positionAtEnd($longBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $context->builder->load($longOut)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doubleBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $context->builder->load($doubleOut)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slotPtr;
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after MathBaseConvertJitHelper compile (#9584)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'MathBaseConvertJitHelper.php');
            if (null === $block) {
                throw new \LogicException('MathBaseConvertJitHelper.php parseAndCompile failed (#9584)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9584)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after MathBaseConvertRuntime bridge (#9584)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
