<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\ext\standard\VmReflection;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionClass::getModifiers() (#34077, #18335).
 *
 * Thin AOT had no proxy → ExternalMethod → NULL. Compile-unit name→modifiers
 * int table matched with length-checked {@see memcmp} on lowercase spellings
 * (peer of {@see ReflectionClassIsFinalRuntime} / {@see ReflectionClassKindNameTableRuntime}).
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_getModifiers
 */
final class ReflectionClassGetModifiersRuntime
{
    private const ABI = '__phpc_refl_class_get_modifiers';

    public static function invoke(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $nameCstr,
            $nameLen
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

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($i64, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_class_getmod_entry');
        $fold = $fn->appendBasicBlock('refl_class_getmod_fold');
        $zero = $fn->appendBasicBlock('refl_class_getmod_zero');
        $context->builder->positionAtEnd($entry);
        $nameCstr = $fn->getParam(0);
        $nameLen = $fn->getParam(1);

        $maxLen = 512;
        $buf = $context->builder->alloca($i8->arrayType($maxLen));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt($maxLen, false)
        );
        $context->builder->branchIf($tooLong, $zero, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock('refl_gmod_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_gmod_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_gmod_fold_body');
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
        $pairs = self::lcNameToModifiers($context);
        $checkBlock = $afterFold;
        $n = \count($pairs);
        $i = 0;
        foreach ($pairs as $lcName => $mods) {
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
            $hit = $fn->appendBasicBlock('refl_gmod_hit_'.$i);
            $next = ($i === $n - 1)
                ? $zero
                : $fn->appendBasicBlock('refl_gmod_try_'.($i + 1));
            $context->builder->branchIf($match, $hit, $next);

            $context->builder->positionAtEnd($hit);
            $context->builder->returnValue($i64->constInt((int) $mods, true));

            $checkBlock = $next;
            ++$i;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($zero);
        }

        $context->builder->positionAtEnd($zero);
        $context->builder->returnValue($i64->constInt(0, true));

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Non-zero ReflectionClass::getModifiers() values keyed by lowercase class name.
     * Zero-modifier classes omit from the table (ABI default return 0).
     *
     * @return array<string, int>
     */
    private static function lcNameToModifiers(Context $context): array
    {
        $pairs = [];

        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $classId => $className) {
            $classId = (int) $classId;
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display) {
                continue;
            }
            $lc = strtolower(ltrim($display, '\\'));
            if ('' === $lc) {
                continue;
            }
            $mods = self::modifiersFromObject($object, $lc, $classId);
            if (0 !== $mods) {
                $pairs[$lc] = $mods;
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry) {
                    continue;
                }
                $mods = VmReflection::classEntryToReflectionModifiers($entry);
                if (0 === $mods) {
                    continue;
                }
                $lc = strtolower(ltrim((string) $entry->name, '\\'));
                if ('' !== $lc) {
                    $pairs[$lc] = $mods;
                }
            }
        }

        ksort($pairs);

        return $pairs;
    }

    private static function modifiersFromObject(Type\Object_ $object, string $lc, int $classId): int
    {
        // php-src zim_ReflectionClass_get_modifiers — interfaces/traits report 0 (#18335).
        if ($object->isInterfaceClassLc($lc) || $object->isTraitClass($lc)) {
            return 0;
        }
        $modifiers = 0;
        if (self::objectIsAbstractClass($object, $lc, $classId)) {
            $modifiers |= VmReflection::REFLECTION_CLASS_IS_EXPLICIT_ABSTRACT;
        }
        if ($object->isEnumClassLc($lc) || $object->isFinalClassLc($lc)) {
            $modifiers |= VmReflection::REFLECTION_CLASS_IS_FINAL;
        }
        if ($object->isReadonlyClass($classId)) {
            $modifiers |= VmReflection::REFLECTION_CLASS_IS_READONLY;
        }

        return $modifiers;
    }

    private static function objectIsAbstractClass(Type\Object_ $object, string $lc, int $classId): bool
    {
        if ($object->isAbstractClassLc($lc)) {
            return true;
        }
        if ($object->isEnumClassLc($lc)) {
            return false;
        }
        foreach ($object->declaredMethodNames($classId) as $methodLc) {
            $vis = $object->methodVisibility($classId, $methodLc);
            if (($vis & \PHPCfg\Func::FLAG_ABSTRACT) !== 0) {
                return true;
            }
        }

        return false;
    }
}
