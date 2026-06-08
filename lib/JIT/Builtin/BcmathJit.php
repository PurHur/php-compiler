<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM bcmath runtime bodies for JIT/AOT (__compiler_bc*, issue #6100).
 *
 * This is a pure LLVM fallback runtime (no C files) with libc parsing/formatting.
 */
final class BcmathJit
{
    private const DEFAULT_SCALE_GLOBAL = '__phpc_bcmath_default_scale';

    public static function implement(Context $context): void
    {
        self::ensureExternalDeclarations($context);
        self::ensureDefaultScaleGlobal($context);
        self::implementIfMissing($context, '__phpc_bcmath_resolve_scale', self::emitResolveScale(...));
        self::implementIfMissing($context, '__phpc_bcmath_read_double', self::emitReadDouble(...));
        self::implementIfMissing($context, '__phpc_bcmath_format', self::emitFormat(...));
        self::implementIfMissing($context, '__phpc_bcmath_trunc_scale', self::emitTruncScale(...));
        self::implementIfMissing($context, '__phpc_bcmath_mod_pow', self::emitModPow(...));
        self::implementIfMissing($context, '__compiler_bcscale', self::emitBcscale(...));
        self::implementIfMissing($context, '__compiler_bcadd', self::emitBcadd(...));
        self::implementIfMissing($context, '__compiler_bcsub', self::emitBcsub(...));
        self::implementIfMissing($context, '__compiler_bcmul', self::emitBcmul(...));
        self::implementIfMissing($context, '__compiler_bcdiv', self::emitBcdiv(...));
        self::implementIfMissing($context, '__compiler_bccomp', self::emitBccomp(...));
        self::implementIfMissing($context, '__compiler_bcpowmod', self::emitBcpowmod(...));
    }

    private static function ensureDefaultScaleGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::DEFAULT_SCALE_GLOBAL)) {
            return;
        }
        $global = $context->module->addGlobal($context->getTypeFromString('int64'), self::DEFAULT_SCALE_GLOBAL);
        $global->setInitializer($context->getTypeFromString('int64')->constInt(0, true));
    }

    private static function ensureExternalDeclarations(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $void = $context->context->voidType();

        self::ensureExternalFunction($context, 'malloc', $context->context->functionType($i8p, false, $i64));
        self::ensureExternalFunction($context, 'free', $context->context->functionType($void, false, $i8p));
        self::ensureExternalFunction($context, 'strlen', $context->context->functionType($i64, false, $i8p));
        self::ensureExternalFunction($context, 'strcmp', $context->context->functionType($i32, false, $i8p, $i8p));
        self::ensureExternalFunction($context, 'memcpy', $context->context->functionType($i8p, false, $i8p, $i8p, $i64));
        self::ensureExternalFunction($context, 'strtod', $context->context->functionType($double, false, $i8p, $i8pp));
        self::ensureExternalFunction($context, 'pow', $context->context->functionType($double, false, $double, $double));
        self::ensureExternalFunction($context, 'floor', $context->context->functionType($double, false, $double));
        self::ensureExternalFunction($context, 'fmod', $context->context->functionType($double, false, $double, $double));
        self::ensureExternalFunction($context, 'snprintf', $context->context->functionType($i32, true, $i8p, $i64, $i8p));
    }

    private static function ensureExternalFunction(Context $context, string $name, $signature): void
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn) {
            $fn = $context->module->addFunction($name, $signature);
        }
        $context->registerFunction($name, $fn);
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
        $fn = null !== $probe ? $probe : self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $str = $context->getTypeFromString('__string__*');

        return match ($name) {
            '__phpc_bcmath_resolve_scale' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $i32)
            ),
            '__phpc_bcmath_read_double' => $context->module->addFunction(
                $name,
                $context->context->functionType($double, false, $str)
            ),
            '__phpc_bcmath_format' => $context->module->addFunction(
                $name,
                $context->context->functionType($str, false, $double, $i64)
            ),
            '__phpc_bcmath_trunc_scale' => $context->module->addFunction(
                $name,
                $context->context->functionType($double, false, $double, $i64)
            ),
            '__phpc_bcmath_mod_pow' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $i64, $i64)
            ),
            '__compiler_bcscale' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $i32)
            ),
            '__compiler_bcadd',
            '__compiler_bcsub',
            '__compiler_bcmul',
            '__compiler_bcdiv' => $context->module->addFunction(
                $name,
                $context->context->functionType($str, false, $str, $str, $i64, $i32)
            ),
            '__compiler_bccomp' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $str, $str, $i64, $i32)
            ),
            '__compiler_bcpowmod' => $context->module->addFunction(
                $name,
                $context->context->functionType($str, false, $str, $str, $str, $i64, $i32)
            ),
            default => throw new \LogicException('Unknown bcmath runtime function: '.$name),
        };
    }

    private static function emitResolveScale(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $useDefault = $fn->appendBasicBlock('use_default');
        $done = $fn->appendBasicBlock('done');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $hasScale = $fn->getParam(1);
        $isDefault = $context->builder->icmp(Builder::INT_EQ, $hasScale, $i32->constInt(-1, true));
        $context->builder->branchIf($isDefault, $useDefault, $done);
        $context->builder->positionAtEnd($useDefault);
        $global = $context->module->getNamedGlobal(self::DEFAULT_SCALE_GLOBAL);
        $resolved = $context->builder->load($global);
        $context->builder->returnValue($resolved);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($fn->getParam(0));
    }

    private static function emitReadDouble(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i64 = $context->getTypeFromString('int64');
        $src = $context->builder->call($context->lookupFunction('__string__separate'), $fn->getParam(0));
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $chars = $context->builder->structGep($src, $map['value']);
        $allocSize = $context->builder->add($len, $i64->constInt(1, false));
        $buf = $context->builder->call($context->lookupFunction('malloc'), $allocSize);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $buf,
            $chars,
            $len
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($buf, $len));
        // Keep libc parity with C runtime code paths.
        $context->builder->call($context->lookupFunction('strlen'), $buf);
        $parsed = $context->builder->call(
            $context->lookupFunction('strtod'),
            $buf,
            $i8pp->constNull()
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($parsed);
    }

    private static function emitFormat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $check = $fn->appendBasicBlock('check');
        $neg = $fn->appendBasicBlock('neg');
        $ok = $fn->appendBasicBlock('ok');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $scale = $fn->getParam(1);
        $scaleSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($scale, $scaleSlot);
        $context->builder->branch($check);

        $context->builder->positionAtEnd($check);
        $loadedScale = $context->builder->load($scaleSlot);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $loadedScale, $i64->constInt(0, true));
        $context->builder->branchIf($isNeg, $neg, $ok);

        $context->builder->positionAtEnd($neg);
        $context->builder->store($i64->constInt(0, false), $scaleSlot);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($ok);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $i64->constInt(256, false));
        $fmt = $context->builder->pointerCast($context->constantFromString('%.*f'), $i8p);
        $scaleI32 = $context->builder->trunc($context->builder->load($scaleSlot), $i32);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $buf,
            $i64->constInt(256, false),
            $fmt,
            $scaleI32,
            $fn->getParam(0)
        );
        $len = $context->builder->call($context->lookupFunction('strlen'), $buf);
        $out = $context->builder->call($context->lookupFunction('__string__init'), $len, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($out);
    }

    private static function emitTruncScale(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $double = $context->getTypeFromString('double');
        $pow = $context->builder->call(
            $context->lookupFunction('pow'),
            $double->constReal(10.0),
            $context->builder->siToFp($fn->getParam(1), $double)
        );
        $scaled = $context->builder->fMul($fn->getParam(0), $pow);
        $floor = $context->builder->call($context->lookupFunction('floor'), $scaled);
        $context->builder->returnValue($context->builder->fDiv($floor, $pow));
    }

    private static function emitModPow(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $baseSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $expSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $resSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $mod = $fn->getParam(2);
        $baseInit = $context->builder->signedRem($fn->getParam(0), $mod);
        $context->builder->store($baseInit, $baseSlot);
        $context->builder->store($fn->getParam(1), $expSlot);
        $context->builder->store($i64->constInt(1, false), $resSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $exp = $context->builder->load($expSlot);
        $cont = $context->builder->icmp(Builder::INT_SGT, $exp, $i64->constInt(0, false));
        $context->builder->branchIf($cont, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $res = $context->builder->load($resSlot);
        $base = $context->builder->load($baseSlot);
        $isOdd = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($exp, $i64->constInt(1, false)),
            $i64->constInt(1, false)
        );
        $oddBlock = $fn->appendBasicBlock('odd');
        $afterOdd = $fn->appendBasicBlock('after_odd');
        $context->builder->branchIf($isOdd, $oddBlock, $afterOdd);

        $context->builder->positionAtEnd($oddBlock);
        $newRes = $context->builder->signedRem($context->builder->mul($res, $base), $mod);
        $context->builder->store($newRes, $resSlot);
        $context->builder->branch($afterOdd);

        $context->builder->positionAtEnd($afterOdd);
        $base = $context->builder->load($baseSlot);
        $context->builder->store($context->builder->signedRem($context->builder->mul($base, $base), $mod), $baseSlot);
        $exp = $context->builder->load($expSlot);
        $context->builder->store($context->builder->lShr($exp, $i64->constInt(1, false)), $expSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($resSlot));
    }

    private static function emitBcscale(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $set = $fn->appendBasicBlock('set');
        $done = $fn->appendBasicBlock('done');
        $context->builder->positionAtEnd($entry);
        $global = $context->module->getNamedGlobal(self::DEFAULT_SCALE_GLOBAL);
        $old = $context->builder->load($global);
        $hasScale = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $shouldSet = $context->builder->icmp(Builder::INT_NE, $hasScale, $i32->constInt(-1, true));
        $context->builder->branchIf($shouldSet, $set, $done);
        $context->builder->positionAtEnd($set);
        $context->builder->store($fn->getParam(0), $global);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($old);
    }

    private static function emitBcadd(Context $context, LlvmFunction $fn): void
    {
        self::emitBinaryMath($context, $fn, 'add');
    }

    private static function emitBcsub(Context $context, LlvmFunction $fn): void
    {
        self::emitBinaryMath($context, $fn, 'sub');
    }

    private static function emitBcmul(Context $context, LlvmFunction $fn): void
    {
        self::emitBinaryMath($context, $fn, 'mul');
    }

    private static function emitBcdiv(Context $context, LlvmFunction $fn): void
    {
        self::emitBinaryMath($context, $fn, 'div');
    }

    private static function emitBinaryMath(Context $context, LlvmFunction $fn, string $op): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $left = $context->builder->call($context->lookupFunction('__phpc_bcmath_read_double'), $fn->getParam(0));
        $right = $context->builder->call($context->lookupFunction('__phpc_bcmath_read_double'), $fn->getParam(1));
        $scale = $context->builder->call($context->lookupFunction('__phpc_bcmath_resolve_scale'), $fn->getParam(2), $fn->getParam(3));
        $double = $context->getTypeFromString('double');
        $result = match ($op) {
            'add' => $context->builder->fAdd($left, $right),
            'sub' => $context->builder->fSub($left, $right),
            'mul' => $context->builder->fMul($left, $right),
            'div' => $context->builder->select(
                $context->builder->fcmp(Builder::REAL_OEQ, $right, $double->constReal(0.0)),
                $double->constReal(0.0),
                $context->builder->fDiv($left, $right)
            ),
            default => $double->constReal(0.0),
        };
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__phpc_bcmath_format'), $result, $scale)
        );
    }

    private static function emitBccomp(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $lt = $fn->appendBasicBlock('lt');
        $gt = $fn->appendBasicBlock('gt');
        $gtRet = $fn->appendBasicBlock('gt_ret');
        $eq = $fn->appendBasicBlock('eq');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $left = $context->builder->call($context->lookupFunction('__phpc_bcmath_read_double'), $fn->getParam(0));
        $right = $context->builder->call($context->lookupFunction('__phpc_bcmath_read_double'), $fn->getParam(1));
        $scale = $context->builder->call($context->lookupFunction('__phpc_bcmath_resolve_scale'), $fn->getParam(2), $fn->getParam(3));
        $left = $context->builder->call($context->lookupFunction('__phpc_bcmath_trunc_scale'), $left, $scale);
        $right = $context->builder->call($context->lookupFunction('__phpc_bcmath_trunc_scale'), $right, $scale);
        $isLt = $context->builder->fcmp(Builder::REAL_OLT, $left, $right);
        $isGt = $context->builder->fcmp(Builder::REAL_OGT, $left, $right);
        $context->builder->branchIf($isLt, $lt, $gt);
        $context->builder->positionAtEnd($lt);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->builder->positionAtEnd($gt);
        $context->builder->branchIf($isGt, $gtRet, $eq);
        $context->builder->positionAtEnd($gtRet);
        $context->builder->returnValue($i64->constInt(1, true));
        $context->builder->positionAtEnd($eq);
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function emitBcpowmod(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $zeroMod = $fn->appendBasicBlock('zero_mod');
        $work = $fn->appendBasicBlock('work');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $scale = $context->builder->call($context->lookupFunction('__phpc_bcmath_resolve_scale'), $fn->getParam(3), $fn->getParam(4));
        $base = $context->builder->fpToSi(
            $context->builder->call($context->lookupFunction('__phpc_bcmath_read_double'), $fn->getParam(0)),
            $i64
        );
        $exp = $context->builder->fpToSi(
            $context->builder->call($context->lookupFunction('__phpc_bcmath_read_double'), $fn->getParam(1)),
            $i64
        );
        $mod = $context->builder->fpToSi(
            $context->builder->call($context->lookupFunction('__phpc_bcmath_read_double'), $fn->getParam(2)),
            $i64
        );
        $isZero = $context->builder->icmp(Builder::INT_EQ, $mod, $i64->constInt(0, false));
        $context->builder->branchIf($isZero, $zeroMod, $work);

        $context->builder->positionAtEnd($zeroMod);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__phpc_bcmath_format'), $double->constReal(0.0), $scale)
        );

        $context->builder->positionAtEnd($work);
        $powmod = $context->builder->call($context->lookupFunction('__phpc_bcmath_mod_pow'), $base, $exp, $mod);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__phpc_bcmath_format'),
                $context->builder->siToFp($powmod, $double),
                $scale
            )
        );
    }
}
