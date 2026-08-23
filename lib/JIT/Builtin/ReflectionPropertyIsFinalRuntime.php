<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionProperty::isFinal() (#34047, #23845, #27315).
 *
 * Final flags live on Object_::finalPropertyNames (markPropertyFinal during
 * DECLARE_PROPERTY), not on thin-standalone VM ClassEntry — emit a name table.
 *
 * Thin AOT previously used {@see StringCaseCompare} which always "matched", so
 * every property reported isFinal()===true whenever the table was non-empty
 * (#34047). Peer of #34043 ReflectionClass::isFinal: ASCII-fold + length-checked
 * {@see memcmp} on class and property names.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionProperty_isFinal
 * (prop flags & ZEND_ACC_FINAL).
 */
final class ReflectionPropertyIsFinalRuntime
{
    private const ABI = '__phpc_refl_property_is_final';

    public static function invoke(Context $context, Value $classStr, Value $propStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $classStr,
            $propStr
        );
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::ensureMemcmpDecl($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($i1, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_property_is_final_entry');
        $context->builder->positionAtEnd($entry);
        $classArg = $fn->getParam(0);
        $propArg = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];

        $classCstr = $context->builder->pointerCast(
            $context->builder->structGep($classArg, $strMap['value']),
            $i8p
        );
        $propCstr = $context->builder->pointerCast(
            $context->builder->structGep($propArg, $strMap['value']),
            $i8p
        );
        $classLen = $context->builder->zExt(
            $context->builder->load($context->builder->structGep($classArg, $strMap['length'])),
            $sizeT
        );
        $propLen = $context->builder->zExt(
            $context->builder->load($context->builder->structGep($propArg, $strMap['length'])),
            $sizeT
        );

        $trueBlock = $fn->appendBasicBlock('refl_prop_final_yes');
        $falseBlock = $fn->appendBasicBlock('refl_prop_final_no');

        $maxLen = 512;
        // Allocas must stay in the entry block before any terminator (#34047 verify).
        $classBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $classBufPtr = $context->builder->pointerCast($classBuf, $i8p);
        $propBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $propBufPtr = $context->builder->pointerCast($propBuf, $i8p);

        $maxConst = $sizeT->constInt($maxLen, false);
        $classTooLong = $context->builder->icmp(Builder::INT_UGT, $classLen, $maxConst);
        $propTooLong = $context->builder->icmp(Builder::INT_UGT, $propLen, $maxConst);
        $tooLong = $context->builder->or($classTooLong, $propTooLong);
        $foldClass = $fn->appendBasicBlock('refl_prop_final_fold_class');
        $context->builder->branchIf($tooLong, $falseBlock, $foldClass);

        $foldProp = $fn->appendBasicBlock('refl_prop_final_fold_prop');
        $afterFold = $fn->appendBasicBlock('refl_prop_final_after_fold');
        self::emitAsciiFold(
            $context,
            $fn,
            $foldClass,
            'refl_prop_final_class',
            $classCstr,
            $classLen,
            $classBufPtr,
            $sizeT,
            $i8,
            $foldProp
        );
        self::emitAsciiFold(
            $context,
            $fn,
            $foldProp,
            'refl_prop_final_prop',
            $propCstr,
            $propLen,
            $propBufPtr,
            $sizeT,
            $i8,
            $afterFold
        );

        $pairs = self::collectFinalPairs($context);
        $checkBlock = $afterFold;
        $n = \count($pairs);
        foreach ($pairs as $idx => [$classLc, $propLc]) {
            $context->builder->positionAtEnd($checkBlock);
            $wantClassLenInt = \strlen($classLc);
            $wantPropLenInt = \strlen($propLc);
            $wantClassLen = $sizeT->constInt($wantClassLenInt, false);
            $wantPropLen = $sizeT->constInt($wantPropLenInt, false);

            $wantClassGlobal = $context->constantStringFromString($classLc);
            $wantClassStr = $context->builder->load($wantClassGlobal);
            $wantClassCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantClassStr, $strMap['value']),
                $i8p
            );
            $wantPropGlobal = $context->constantStringFromString($propLc);
            $wantPropStr = $context->builder->load($wantPropGlobal);
            $wantPropCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantPropStr, $strMap['value']),
                $i8p
            );

            $classLenEq = $context->builder->icmp(Builder::INT_EQ, $classLen, $wantClassLen);
            $classCmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $classBufPtr,
                $wantClassCstr,
                $context->builder->zExt($wantClassLen, $i64)
            );
            $classEq = $context->builder->and(
                $classLenEq,
                $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false))
            );

            $propLenEq = $context->builder->icmp(Builder::INT_EQ, $propLen, $wantPropLen);
            $propCmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $propBufPtr,
                $wantPropCstr,
                $context->builder->zExt($wantPropLen, $i64)
            );
            $propEq = $context->builder->and(
                $propLenEq,
                $context->builder->icmp(Builder::INT_EQ, $propCmp, $i32->constInt(0, false))
            );

            $both = $context->builder->and($classEq, $propEq);
            $next = ($idx === $n - 1)
                ? $falseBlock
                : $fn->appendBasicBlock('refl_prop_final_try_'.($idx + 1));
            $context->builder->branchIf($both, $trueBlock, $next);
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($falseBlock);
        }

        $context->builder->positionAtEnd($trueBlock);
        $context->builder->returnValue($i1->constInt(1, false));
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * ASCII-fold {@see $src}/{@see $len} into {@see $bufPtr}, then branch to {@see $cont}.
     *
     * @param mixed $fn LLVM function (supports appendBasicBlock)
     * @param mixed $startBlock
     * @param mixed $sizeT size_t type
     * @param mixed $i8 int8 type
     * @param mixed $cont continuation block
     */
    private static function emitAsciiFold(
        Context $context,
        $fn,
        $startBlock,
        string $prefix,
        Value $src,
        Value $len,
        Value $bufPtr,
        $sizeT,
        $i8,
        $cont
    ): void {
        $context->builder->positionAtEnd($startBlock);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($prefix.'_fold_loop');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $len);
        $body = $fn->appendBasicBlock($prefix.'_fold_body');
        $context->builder->branchIf($done, $cont, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($src, $idx);
        $ch = $context->builder->load($srcPtr);
        $geA = $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('A'), true));
        $leZ = $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('Z'), true));
        $isUpper = $context->builder->and($geA, $leZ);
        $lowered = $context->builder->add($ch, $i8->constInt(32, true));
        $folded = $context->builder->select($isUpper, $lowered, $ch);
        $dstPtr = $context->builder->gep($bufPtr, $idx);
        $context->builder->store($folded, $dstPtr);
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxAlloca
        );
        $context->builder->branch($loop);
    }

    /**
     * @return list<array{0: string, 1: string}> lowercase class + property pairs
     */
    private static function collectFinalPairs(Context $context): array
    {
        $object = $context->type->object;
        $pairs = [];
        foreach ($object->allClassNamesById() as $classId => $className) {
            $display = $object->classNameForId((int) $classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display) {
                continue;
            }
            // Skip engine Reflection* layout classes — only user finals matter for isFinal().
            $lc = strtolower($display);
            if (str_starts_with($lc, 'reflection')) {
                continue;
            }
            foreach ($object->finalPropertyNamesForClassId((int) $classId) as $propLc) {
                $pairs[] = [$lc, strtolower((string) $propLc)];
            }
        }

        return $pairs;
    }
}
