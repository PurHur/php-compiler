<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionClass::isInstantiable() (#34027).
 *
 * Thin AOT had no proxy → ExternalMethod → NULL. Compile-unit name table matched
 * with length-checked {@see memcmp} on lowercase spellings (avoids broken
 * __compiler_strcasecmp / NestedJIT bool bridges under thin AOT).
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_isInstantiable
 */
final class ReflectionClassIsInstantiableRuntime
{
    private const ABI = '__phpc_refl_class_is_instantiable';

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
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($i1, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_class_is_instantiable_entry');
        $fold = $fn->appendBasicBlock('refl_class_is_instantiable_fold');
        $context->builder->positionAtEnd($entry);
        $nameCstr = $fn->getParam(0);
        $nameLen = $fn->getParam(1);

        // Fold ASCII letters to lowercase into an alloca buffer (max 512).
        $maxLen = 512;
        $buf = $context->builder->alloca($i8->arrayType($maxLen));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt($maxLen, false)
        );
        $trueBlock = $fn->appendBasicBlock('refl_class_instantiable_yes');
        $falseBlock = $fn->appendBasicBlock('refl_class_instantiable_no');
        $context->builder->branchIf($tooLong, $trueBlock, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock('refl_isi_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_isi_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_isi_fold_body');
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
        $nonInstantiable = self::nonInstantiableLowerNames($context);
        $checkBlock = $afterFold;
        $n = \count($nonInstantiable);
        foreach ($nonInstantiable as $idxName => $lcName) {
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
            $next = ($idxName === $n - 1)
                ? $trueBlock
                : $fn->appendBasicBlock('refl_isi_try_'.($idxName + 1));
            $context->builder->branchIf($match, $falseBlock, $next);
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($afterFold);
            $context->builder->branch($trueBlock);
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
     * @return list<string>
     */
    private static function nonInstantiableLowerNames(Context $context): array
    {
        $seen = [];
        $add = static function (string $display) use (&$seen): void {
            $lc = strtolower(ltrim($display, '\\'));
            if ('' !== $lc) {
                $seen[$lc] = true;
            }
        };

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
            if ($object->isInterfaceClassLc($lc)
                || $object->isTraitClass($lc)
                || $object->isEnumClassLc($lc)
                || $object->isAbstractClassLc($lc)
            ) {
                $add($display);
                continue;
            }
            foreach ($object->declaredMethodNames($classId) as $methodLc) {
                $vis = $object->methodVisibility($classId, $methodLc);
                if (($vis & \PHPCfg\Func::FLAG_ABSTRACT) !== 0) {
                    $add($display);
                    continue 2;
                }
            }
            if ($object->hasMethod($classId, '__construct')) {
                $ctorVis = $object->methodVisibility($classId, '__construct');
                if (($ctorVis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                    $add($display);
                }
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!\is_object($entry) || !isset($entry->name)) {
                    continue;
                }
                if (!ReflectionSupport::reflectionClassIsInstantiable($entry, $vmCtx)) {
                    $add((string) $entry->name);
                }
            }
        }

        foreach ([
            'Closure', 'Generator', 'WeakReference', 'WeakMap', 'Fiber', 'FiberError',
            'Attribute', 'CURLFile', 'CURLStringFile',
        ] as $builtin) {
            $add($builtin);
        }

        $out = array_keys($seen);
        sort($out);

        return $out;
    }
}
