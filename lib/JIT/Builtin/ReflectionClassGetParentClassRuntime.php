<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\ext\standard\VmReflection;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionClass::getParentClass() parent-name lookup (#34069).
 *
 * Thin AOT had no proxy → ExternalMethod → SIGSEGV on result use. Compile-unit
 * name→parent table matched with length-checked {@see memcmp} on lowercase
 * spellings (peer of {@see ReflectionClassIsCloneableRuntime}).
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_getParentClass
 */
final class ReflectionClassGetParentClassRuntime
{
    private const ABI = '__phpc_refl_class_parent_name';

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
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_class_parent_entry');
        $fold = $fn->appendBasicBlock('refl_class_parent_fold');
        $none = $fn->appendBasicBlock('refl_class_parent_none');
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
        $context->builder->branchIf($tooLong, $none, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock('refl_gpc_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_gpc_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_gpc_fold_body');
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
        $pairs = self::childLcToParentDisplay($context);
        $checkBlock = $afterFold;
        $n = \count($pairs);
        $i = 0;
        foreach ($pairs as $lcName => $parentDisplay) {
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
            $hit = $fn->appendBasicBlock('refl_gpc_hit_'.$i);
            $next = ($i === $n - 1)
                ? $none
                : $fn->appendBasicBlock('refl_gpc_try_'.($i + 1));
            $context->builder->branchIf($match, $hit, $next);

            $context->builder->positionAtEnd($hit);
            $parentGlobal = $context->constantStringFromString($parentDisplay);
            $parentStr = $context->builder->load($parentGlobal);
            $context->builder->returnValue($parentStr);

            $checkBlock = $next;
            ++$i;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($none);
        }

        $context->builder->positionAtEnd($none);
        $context->builder->returnValue($strPtr->constNull());

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @return array<string, string> lowercase child name → parent display name
     */
    private static function childLcToParentDisplay(Context $context): array
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
            $parent = $object->parentClassDisplayName($display);
            if (null === $parent || '' === $parent) {
                continue;
            }
            $lc = strtolower(ltrim($display, '\\'));
            if ('' !== $lc) {
                $pairs[$lc] = $parent;
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!\is_object($entry) || !isset($entry->name)) {
                    continue;
                }
                if (!empty($entry->isInterface) || !empty($entry->isTrait) || !empty($entry->isEnum)) {
                    continue;
                }
                $parentName = VmReflection::parentClassName($entry, $vmCtx);
                if (null === $parentName || '' === $parentName) {
                    continue;
                }
                $lc = strtolower(ltrim((string) $entry->name, '\\'));
                if ('' !== $lc && !isset($pairs[$lc])) {
                    $pairs[$lc] = $parentName;
                }
            }
        }

        ksort($pairs);

        return $pairs;
    }
}
