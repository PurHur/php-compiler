<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT bridge for date_add/date_sub/date_modify/date_diff scalar helpers (#8770, #26750).
 *
 * Routes {@see __phpc_date_apply_interval}, {@see __phpc_date_modify_delta}, and
 * {@see __phpc_date_diff_scalars} through compiled {@see DateMutationJitHelper} PHP
 * (SSOT {@see VmDateTimeNative}) instead of hand-rolled calendar LLVM.
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringFilterInt #26699).
 * php-src: ext/date/php_date.c
 */
final class DateMutationRuntime
{
    private const HELPER_PATH = '/ext/standard/DateMutationJitHelper.php';

    private const MODIFY_DELTA = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::modifyDelta';

    private const COMPUTE_APPLY = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::computeApplyInterval';

    private const APPLY_OUT_TS = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::applyOutTimestamp';

    private const APPLY_OUT_MICRO = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::applyOutMicrosecond';

    private const COMPUTE_DIFF = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::computeDiffScalars';

    private const DIFF_OUT_Y = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutY';

    private const DIFF_OUT_M = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutM';

    private const DIFF_OUT_D = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutD';

    private const DIFF_OUT_H = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutH';

    private const DIFF_OUT_I = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutI';

    private const DIFF_OUT_S = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutS';

    private const DIFF_OUT_F = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutF';

    private const DIFF_OUT_INVERT = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutInvert';

    private const DIFF_OUT_DAYS = 'PHPCompiler\\ext\\standard\\DateMutationJitHelper::diffOutDays';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MODIFY_DELTA,
        self::COMPUTE_APPLY,
        self::APPLY_OUT_TS,
        self::APPLY_OUT_MICRO,
        self::COMPUTE_DIFF,
        self::DIFF_OUT_Y,
        self::DIFF_OUT_M,
        self::DIFF_OUT_D,
        self::DIFF_OUT_H,
        self::DIFF_OUT_I,
        self::DIFF_OUT_S,
        self::DIFF_OUT_F,
        self::DIFF_OUT_INVERT,
        self::DIFF_OUT_DAYS,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_date_apply_interval',
        '__phpc_date_modify_delta',
        '__phpc_date_diff_scalars',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_date_apply_interval');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementApplyIntervalBridge($context);
        self::implementModifyDeltaBridge($context);
        self::implementDiffScalarsBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementModifyDeltaBridge(Context $context): void
    {
        $abiName = '__phpc_date_modify_delta';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $ft = $context->context->functionType($voidTy, false, $i64, $i64, $i64, $i8p, $i64p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('date_md_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $tzStr = self::cstrToString($context, $fn->getParam(3));
        $newTs = $context->builder->call(
            self::helperFunction($context, self::MODIFY_DELTA),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $tzStr
        );
        $context->builder->store($newTs, $fn->getParam(4));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementApplyIntervalBridge(Context $context): void
    {
        $abiName = '__phpc_date_apply_interval';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $ft = $context->context->functionType(
            $voidTy,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $dbl,
            $i64,
            $i1,
            $i8p,
            $i64p,
            $i64p
        );
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('date_ai_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $tzStr = self::cstrToString($context, $fn->getParam(11));
        $context->builder->call(
            self::helperFunction($context, self::COMPUTE_APPLY),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3),
            $fn->getParam(4),
            $fn->getParam(5),
            $fn->getParam(6),
            $fn->getParam(7),
            $fn->getParam(8),
            $fn->getParam(9),
            $fn->getParam(10),
            $fn->getParam(12),
            $fn->getParam(13),
            $tzStr
        );
        $outTs = $context->builder->call(self::helperFunction($context, self::APPLY_OUT_TS));
        $outMicro = $context->builder->call(self::helperFunction($context, self::APPLY_OUT_MICRO));
        $context->builder->store($outTs, $fn->getParam(14));
        $context->builder->store($outMicro, $fn->getParam(15));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementDiffScalarsBridge(Context $context): void
    {
        $abiName = '__phpc_date_diff_scalars';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $dblp = $context->getTypeFromString('double*');
        // baseTs, baseUs, targetTs, targetUs, absolute, tz, outY..outS, outF, outInvert, outDays (#26693)
        $ft = $context->context->functionType(
            $voidTy,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i1,
            $i8p,
            $i64p,
            $i64p,
            $i64p,
            $i64p,
            $i64p,
            $i64p,
            $dblp,
            $i64p,
            $i64p
        );
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('date_df_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $tzStr = self::cstrToString($context, $fn->getParam(5));
        $context->builder->call(
            self::helperFunction($context, self::COMPUTE_DIFF),
            $fn->getParam(0),
            $fn->getParam(2),
            $fn->getParam(4),
            $tzStr,
            $fn->getParam(1),
            $fn->getParam(3)
        );
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_Y)), $fn->getParam(6));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_M)), $fn->getParam(7));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_D)), $fn->getParam(8));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_H)), $fn->getParam(9));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_I)), $fn->getParam(10));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_S)), $fn->getParam(11));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_F)), $fn->getParam(12));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_INVERT)), $fn->getParam(13));
        $context->builder->store($context->builder->call(self::helperFunction($context, self::DIFF_OUT_DAYS)), $fn->getParam(14));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26750');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureValueStringHelpers($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26750'
        );
    }

    private static function ensureValueStringHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $charPtr)
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $charPtr)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after DateMutationRuntime bridge (#8770)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
