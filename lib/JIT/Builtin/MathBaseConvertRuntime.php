<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_base_convert / phpc_basetozval_result via MathBaseConvertJitHelper PHP (#9584, #26884).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GlobalIntrospectionNameRuntime #22070).
 * Replaces {@see MathBaseConvertJit} LLVM (~950 LOC). SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit (peer #26869).
 * NestedJIT of other helpers may only declare ABIs (#27012); bodies emit outside NestedJIT.
 * php-src: ext/standard/math.c
 */
final class MathBaseConvertRuntime
{
    private const HELPER_PATH = '/ext/standard/MathBaseConvertJitHelper.php';

    private const BASE_CONVERT = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::baseConvert';

    private const PARSE_BASE_TO_ZVAL = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::parseBaseToZval';

    private const LAST_LONG = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::lastLong';

    private const LAST_DOUBLE = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::lastDouble';

    private const LAST_INVALID_CHARS = 'PHPCompiler\\ext\\standard\\MathBaseConvertJitHelper::lastInvalidChars';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BASE_CONVERT,
        self::PARSE_BASE_TO_ZVAL,
        self::LAST_LONG,
        self::LAST_DOUBLE,
        self::LAST_INVALID_CHARS,
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
        // NestedJIT of MathBaseConvertJitHelper must not emit outer ABI bridges (#26884).
        // Other NestedJIT units (WeakRef→hexdec) may still emit calls — declare+register
        // the ABIs so lookup does not throw; bodies are filled outside NestedJIT (#27012).
        if (NestedJitCompileScope::isActive()) {
            self::declareRuntimeAbisForNestedJit($context);

            return;
        }

        $probe = $context->module->getNamedFunction('phpc_base_convert');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit
        // (hexdec/bindec thin AOT: "Current basic block has no parent function", #26884 / peer #26869).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_base_convert', self::implementBaseConvertBridge(...));
        self::implementIfMissing($context, 'phpc_basetozval_result', self::implementBaseToZvalResultBridge(...));
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Declare phpc_base_convert / phpc_basetozval_result without bridge bodies (#27012). */
    private static function declareRuntimeAbisForNestedJit(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $context->registerFunction($name, self::declareFunction($context, $name));
            }
        }
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
        $savedLowering = $context->loweringLlvmFunction;
        $savedActive = $context->activeFunction;
        $context->activeFunction = $name;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : null;
        try {
            $emit($context, $fn);
            $context->registerFunction($name, $fn);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $i64Ptr = $context->getTypeFromString('int64*');
        $doublePtr = $context->getTypeFromString('double*');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        return match ($name) {
            'phpc_base_convert' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64, $i64)
            ),
            'phpc_basetozval_result' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i64, $i64Ptr, $doublePtr)
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

        // Pass `__string__*` straight through — i8*/__string__init round-trip made NestedJIT
        // string offsets ints so ord() TypeError'd under thin AOT (#26884).
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::BASE_CONVERT),
            [$fn->getParam(0), $fromI64, $toI64]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        self::emitInvalidRadixCharsDeprecationIfNeeded($context);
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

        // NestedJIT maps PHP `int` returns to i64; phpc_basetozval_result is i32 (#26511).
        $tagWide = $context->builder->call(
            self::helperFunction($context, self::PARSE_BASE_TO_ZVAL),
            $fn->getParam(0),
            $baseI64
        );
        $tag = $context->builder->trunc($tagWide, $i32);
        self::emitInvalidRadixCharsDeprecationIfNeeded($context);

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
        // NestedJIT `int` → i64; keep as i64 for __value__writeLong (#26511).
        $longI64 = $context->builder->call(self::helperFunction($context, self::LAST_LONG));
        $context->builder->branch($afterFetch);

        $context->builder->positionAtEnd($fetchDouble);
        $dblVal = $context->builder->call(self::helperFunction($context, self::LAST_DOUBLE));
        $context->builder->branch($afterFetch);

        $context->builder->positionAtEnd($afterFetch);
        $longPhi = $context->builder->phi($i64, 'mbc_btz_long_phi');
        $dblPhi = $context->builder->phi($double, 'mbc_btz_dbl_phi');
        $longPhi->addIncoming($longI64, $fetchLong);
        $longPhi->addIncoming($i64->constInt(0, false), $fetchDouble);
        $dblPhi->addIncoming($double->constReal(0.0), $fetchLong);
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

    /** php-src math.c invalid-digit E_DEPRECATED after NestedJIT parse (#24950). */
    private static function emitInvalidRadixCharsDeprecationIfNeeded(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        StringTriggerErrorJit::implement($context);
        $i32 = $context->getTypeFromString('int32');
        $hadWide = $context->builder->call(self::helperFunction($context, self::LAST_INVALID_CHARS));
        $had = $context->builder->trunc($hadWide, $i32);
        $isSet = $context->builder->icmp(Builder::INT_NE, $had, $i32->constInt(0, false));
        $warn = BasicBlockHelper::append($context, 'mbc_invalid_radix_dep');
        $cont = BasicBlockHelper::append($context, 'mbc_invalid_radix_cont');
        $context->builder->branchIf($isSet, $warn, $cont);
        $context->builder->positionAtEnd($warn);
        JitBuiltinWarning::emitDeprecated($context, VmMath::INVALID_RADIX_CHARS_MESSAGE);
        $context->builder->branch($cont);
        $context->builder->positionAtEnd($cont);
    }

    public static function baseToZvalCall(Context $context, Value $strPtr, int $base): Value
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $longOut = BasicBlockHelper::entryAlloca($context, $i64);
        $doubleOut = BasicBlockHelper::entryAlloca($context, $double);
        $isDouble = $context->builder->call(
            $context->lookupFunction('phpc_basetozval_result'),
            $strPtr,
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
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22124'
        );
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
