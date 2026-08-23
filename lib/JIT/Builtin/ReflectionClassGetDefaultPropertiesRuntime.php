<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT name→defaults dispatch for ReflectionClass::getDefaultProperties() (#34091).
 *
 * Emits fold+memcmp against compile-unit user class names **in the current function**
 * (not a separate ABI) so {@see GetClassVarsRuntime} / HashTableHelper blocks stay in-function.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_getDefaultProperties
 */
final class ReflectionClassGetDefaultPropertiesRuntime
{
    /**
     * Lowercase folded name match → {@see GetClassVarsRuntime::emitReflectionDefaultProperties()}.
     */
    public static function emitFromNameCstr(
        Context $context,
        Value $nameCstr,
        Value $nameLen
    ): Value {
        LibcExtern::ensureMemcmpDecl($context);

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        $resultSlot = $context->builder->alloca($context->getTypeFromString('__value__*'));
        $resultSlot->setName('refl_gdp_result');

        $fold = BasicBlockHelper::append($context, 'refl_gdp_fold');
        $empty = BasicBlockHelper::append($context, 'refl_gdp_empty');
        $cont = BasicBlockHelper::append($context, 'refl_gdp_cont');

        $maxLen = 512;
        $buf = $context->builder->alloca($i8->arrayType($maxLen));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt($maxLen, false)
        );
        $context->builder->branchIf($tooLong, $empty, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = BasicBlockHelper::append($context, 'refl_gdp_fold_loop');
        $afterFold = BasicBlockHelper::append($context, 'refl_gdp_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = BasicBlockHelper::append($context, 'refl_gdp_fold_body');
        $context->builder->branchIf($done, $afterFold, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($nameCstr, $idx);
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

        $context->builder->positionAtEnd($afterFold);
        $names = self::userClassLcNames($context);
        $checkBlock = $afterFold;
        $n = \count($names);
        $i = 0;
        foreach ($names as $lcName) {
            $context->builder->positionAtEnd($checkBlock);
            $wantLenInt = \strlen($lcName);
            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($lcName);
            $wantStr = $context->builder->load($wantGlobal);
            $strMap = $context->structFieldMap['__string__'];
            $wantCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantStr, $strMap['value']),
                $i8p
            );
            $lenEq = $context->builder->icmp(Builder::INT_EQ, $nameLen, $wantLen);
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $bufPtr,
                $wantCstr,
                $context->builder->zExt($wantLen, $i64)
            );
            $nameEq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $match = $context->builder->and($lenEq, $nameEq);
            $hit = BasicBlockHelper::append($context, 'refl_gdp_hit_'.$i);
            $next = ($i === $n - 1)
                ? $empty
                : BasicBlockHelper::append($context, 'refl_gdp_try_'.($i + 1));
            $context->builder->branchIf($match, $hit, $next);

            $context->builder->positionAtEnd($hit);
            $display = self::displayNameForLc($context, $lcName);
            $ptr = GetClassVarsRuntime::emitReflectionDefaultProperties($context, $display);
            $context->builder->store($ptr, $resultSlot);
            $context->builder->branch($cont);

            $checkBlock = $next;
            ++$i;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($empty);
        }

        $context->builder->positionAtEnd($empty);
        $ptr = GetClassVarsRuntime::emitReflectionDefaultProperties($context, '');
        $context->builder->store($ptr, $resultSlot);
        $context->builder->branch($cont);

        $context->builder->positionAtEnd($cont);

        return $context->builder->load($resultSlot);
    }

    /**
     * @return list<string>
     */
    private static function userClassLcNames(Context $context): array
    {
        $names = [];
        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $classId => $className) {
            $classId = (int) $classId;
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display || !$object->hasUserDeclaredClass($display)) {
                continue;
            }
            $lc = strtolower(ltrim($display, '\\'));
            if ('' !== $lc) {
                $names[$lc] = true;
            }
        }
        $out = array_keys($names);
        sort($out);

        return $out;
    }

    private static function displayNameForLc(Context $context, string $lcName): string
    {
        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $classId => $className) {
            $classId = (int) $classId;
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' !== $display && strtolower(ltrim($display, '\\')) === $lcName) {
                return $display;
            }
        }

        return $lcName;
    }
}
