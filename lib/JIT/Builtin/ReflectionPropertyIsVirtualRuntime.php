<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionProperty::isVirtual() (#27516, #34049).
 *
 * Virtual flags live on Object_::virtualPropertyNames (markPropertyVirtual during
 * DECLARE_PROPERTY from PropertyHooks registry). Thin AOT previously used
 * {@see StringCaseCompare} which always "matched", so every property reported
 * isVirtual()===true when the CU table was non-empty (#34049) — peer of #34043 /
 * #34047. Match with ASCII-fold + length-checked {@see memcmp}.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionProperty_isVirtual
 */
final class ReflectionPropertyIsVirtualRuntime
{
    private const ABI = '__phpc_refl_property_is_virtual';

    public static function invoke(Context $context, Value $classStr, Value $propStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $classStr, $propStr);
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
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($i1, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_property_is_virtual_entry');
        $foldClass = $fn->appendBasicBlock('refl_prop_virt_fold_class');
        $foldProp = $fn->appendBasicBlock('refl_prop_virt_fold_prop');
        $afterFold = $fn->appendBasicBlock('refl_prop_virt_after_fold');
        $trueBlock = $fn->appendBasicBlock('refl_prop_virtual_yes');
        $falseBlock = $fn->appendBasicBlock('refl_prop_virtual_no');

        $context->builder->positionAtEnd($entry);
        $classArg = $fn->getParam(0);
        $propArg = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        $classLenI64 = $context->builder->load(
            $context->builder->structGep($classArg, $strMap['length'])
        );
        $propLenI64 = $context->builder->load(
            $context->builder->structGep($propArg, $strMap['length'])
        );
        $classLen = $context->builder->zExt($classLenI64, $sizeT);
        $propLen = $context->builder->zExt($propLenI64, $sizeT);
        $classCstr = $context->builder->pointerCast(
            $context->builder->structGep($classArg, $strMap['value']),
            $i8p
        );
        $propCstr = $context->builder->pointerCast(
            $context->builder->structGep($propArg, $strMap['value']),
            $i8p
        );

        // Fold ASCII letters to lowercase into alloca buffers (max 512).
        $maxLen = 512;
        $classBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $propBuf = $context->builder->alloca($i8->arrayType($maxLen));
        $classBufPtr = $context->builder->pointerCast($classBuf, $i8p);
        $propBufPtr = $context->builder->pointerCast($propBuf, $i8p);
        $classTooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $classLen,
            $sizeT->constInt($maxLen, false)
        );
        $propTooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $propLen,
            $sizeT->constInt($maxLen, false)
        );
        $anyTooLong = $context->builder->or($classTooLong, $propTooLong);
        // Over-long names are not in the table → not virtual.
        $context->builder->branchIf($anyTooLong, $falseBlock, $foldClass);

        self::emitAsciiFoldLoop(
            $context,
            $fn,
            $foldClass,
            $foldProp,
            $classCstr,
            $classLen,
            $classBufPtr,
            $sizeT,
            $i8,
            'refl_prop_virt_cf'
        );
        self::emitAsciiFoldLoop(
            $context,
            $fn,
            $foldProp,
            $afterFold,
            $propCstr,
            $propLen,
            $propBufPtr,
            $sizeT,
            $i8,
            'refl_prop_virt_pf'
        );

        $pairs = self::collectVirtualPairs($context);
        $checkBlock = $afterFold;
        $n = \count($pairs);
        foreach ($pairs as $idx => [$classLc, $propLc]) {
            $context->builder->positionAtEnd($checkBlock);
            $wantClassLenInt = \strlen($classLc);
            $wantPropLenInt = \strlen($propLc);
            $wantClassLen = $sizeT->constInt($wantClassLenInt, false);
            $wantPropLen = $sizeT->constInt($wantPropLenInt, false);
            $wantClassStr = $context->builder->load($context->constantStringFromString($classLc));
            $wantPropStr = $context->builder->load($context->constantStringFromString($propLc));
            $wantClassCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantClassStr, $strMap['value']),
                $i8p
            );
            $wantPropCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantPropStr, $strMap['value']),
                $i8p
            );
            $classLenEq = $context->builder->icmp(Builder::INT_EQ, $classLen, $wantClassLen);
            $propLenEq = $context->builder->icmp(Builder::INT_EQ, $propLen, $wantPropLen);
            $classCmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $classBufPtr,
                $wantClassCstr,
                $context->builder->zExt($wantClassLen, $i64)
            );
            $propCmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $propBufPtr,
                $wantPropCstr,
                $context->builder->zExt($wantPropLen, $i64)
            );
            $classEq = $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false));
            $propEq = $context->builder->icmp(Builder::INT_EQ, $propCmp, $i32->constInt(0, false));
            $both = $context->builder->and(
                $context->builder->and($classLenEq, $propLenEq),
                $context->builder->and($classEq, $propEq)
            );
            $next = ($idx === $n - 1)
                ? $falseBlock
                : $fn->appendBasicBlock('refl_prop_virtual_try_'.($idx + 1));
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
     * Fold ASCII A–Z to a–z from $srcCstr[$srcLen] into $dstBuf; branch to $cont.
     *
     * @param \PHPLLVM\Value|\PHPLLVM\Function_ $fn
     * @param \PHPLLVM\BasicBlock               $startBlock
     * @param \PHPLLVM\BasicBlock               $contBlock
     * @param \PHPLLVM\Type                     $sizeT
     * @param \PHPLLVM\Type                     $i8
     */
    private static function emitAsciiFoldLoop(
        Context $context,
        $fn,
        $startBlock,
        $contBlock,
        Value $srcCstr,
        Value $srcLen,
        Value $dstBuf,
        $sizeT,
        $i8,
        string $prefix
    ): void {
        $context->builder->positionAtEnd($startBlock);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($prefix.'_loop');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $srcLen);
        $body = $fn->appendBasicBlock($prefix.'_body');
        $context->builder->branchIf($done, $contBlock, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($srcCstr, $idx);
        $ch = $context->builder->load($srcPtr);
        $geA = $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('A'), true));
        $leZ = $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('Z'), true));
        $isUpper = $context->builder->and($geA, $leZ);
        $lowered = $context->builder->add($ch, $i8->constInt(32, true));
        $folded = $context->builder->select($isUpper, $lowered, $ch);
        $dstPtr = $context->builder->gep($dstBuf, $idx);
        $context->builder->store($folded, $dstPtr);
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxAlloca
        );
        $context->builder->branch($loop);
    }

    /**
     * Lowercase (class, prop) pairs that must report isVirtual()===true.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function collectVirtualPairs(Context $context): array
    {
        $object = $context->type->object;
        $seen = [];
        $pairs = [];
        $add = static function (string $display, string $prop) use (&$seen, &$pairs): void {
            if ('' === $display || '' === $prop) {
                return;
            }
            $lcClass = strtolower(ltrim($display, '\\'));
            $lcProp = strtolower($prop);
            if ('' === $lcClass || '' === $lcProp) {
                return;
            }
            if (str_starts_with($lcClass, 'reflection')) {
                return;
            }
            $key = $lcClass."\0".$lcProp;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $pairs[] = [$lcClass, $lcProp];
        };

        // PropertyHooks registry is filled before class-body lowering — prefer it so the
        // first isVirtual() call site still sees virtual props (#27516).
        $registry = $context->runtime->vmContext->propertyHookRegistry ?? [];
        foreach ($registry as $lcClass => $props) {
            if (!\is_array($props) || !\is_string($lcClass) || '' === $lcClass) {
                continue;
            }
            $display = (string) $lcClass;
            $classId = $object->classIdForLowerName((string) $lcClass);
            if (null !== $classId) {
                $resolved = $object->classNameForId($classId);
                if (\is_string($resolved) && '' !== $resolved) {
                    $display = $resolved;
                }
            }
            foreach ($props as $propName => $meta) {
                if (!\is_string($propName) || !\is_array($meta) || empty($meta['virtual'])) {
                    continue;
                }
                $add($display, $propName);
            }
        }

        // Inherited virtual marks on child class ids (ReflectionProperty(Child::class, …)).
        foreach ($object->allClassNamesById() as $classId => $className) {
            $display = $object->classNameForId((int) $classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            foreach ($object->virtualPropertyNamesForClassId((int) $classId) as $propLc) {
                $add($display, $propLc);
            }
        }

        return $pairs;
    }
}
