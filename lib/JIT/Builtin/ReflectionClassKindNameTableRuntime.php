<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT name tables for ReflectionClass kind queries (#34032 / #34067).
 *
 * NestedJIT bool bridges ({@see ReflectionSetup::emitKindQuery}) fail module verify
 * under thin AOT; compile-unit lowercase {@see memcmp} tables match Zend for
 * isInterface / isAbstract / isTrait / isEnum / isInternal / isReadOnly
 * (peer of #34027 isInstantiable). isUserDefined is the invert of isInternal
 * at the Call site (#34067).
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_is*
 */
final class ReflectionClassKindNameTableRuntime
{
    /** @var array<string, string> kindLc => ABI symbol */
    private const ABI = [
        'isinterface' => '__phpc_refl_class_is_interface',
        'isabstract' => '__phpc_refl_class_is_abstract',
        'istrait' => '__phpc_refl_class_is_trait',
        'isenum' => '__phpc_refl_class_is_enum',
        'isinternal' => '__phpc_refl_class_is_internal',
        'isreadonly' => '__phpc_refl_class_is_readonly',
    ];

    public static function invoke(Context $context, string $kindLc, Value $nameCstr, Value $nameLen): Value
    {
        $kindLc = strtolower($kindLc);
        if (!isset(self::ABI[$kindLc])) {
            throw new \InvalidArgumentException('Unknown ReflectionClass kind: '.$kindLc);
        }
        self::ensureLinked($context, $kindLc);

        return $context->builder->call(
            $context->lookupFunction(self::ABI[$kindLc]),
            $nameCstr,
            $nameLen
        );
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        foreach (array_keys(self::ABI) as $kindLc) {
            self::ensureLinked($context, $kindLc);
        }
    }

    public static function ensureLinked(Context $context, string $kindLc): void
    {
        $kindLc = strtolower($kindLc);
        $abi = self::ABI[$kindLc] ?? null;
        if (null === $abi) {
            throw new \InvalidArgumentException('Unknown ReflectionClass kind: '.$kindLc);
        }

        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

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
            : $context->module->addFunction($abi, $ft);

        $tag = 'refl_'.$kindLc;
        $entry = $fn->appendBasicBlock($tag.'_entry');
        $fold = $fn->appendBasicBlock($tag.'_fold');
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
        $trueBlock = $fn->appendBasicBlock($tag.'_yes');
        $falseBlock = $fn->appendBasicBlock($tag.'_no');
        // Oversize names cannot match a known kind → false (not instantiable-style true).
        $context->builder->branchIf($tooLong, $falseBlock, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($tag.'_fold_loop');
        $afterFold = $fn->appendBasicBlock($tag.'_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock($tag.'_fold_body');
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
        $matchNames = self::matchLowerNames($context, $kindLc);
        $checkBlock = $afterFold;
        $n = \count($matchNames);
        foreach ($matchNames as $idxName => $lcName) {
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
                ? $falseBlock
                : $fn->appendBasicBlock($tag.'_try_'.($idxName + 1));
            $context->builder->branchIf($match, $trueBlock, $next);
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

        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @return list<string>
     */
    private static function matchLowerNames(Context $context, string $kindLc): array
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
            if (self::objectMatchesKind($object, $kindLc, $lc, $classId)) {
                $add($display);
            }
        }

        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry) {
                    continue;
                }
                if (self::vmEntryMatchesKind($entry, $kindLc)) {
                    $add($entry->name);
                }
            }
        }

        $out = array_keys($seen);
        sort($out);

        return $out;
    }

    private static function objectMatchesKind(Type\Object_ $object, string $kindLc, string $lc, int $classId): bool
    {
        return match ($kindLc) {
            'isinterface' => $object->isInterfaceClassLc($lc),
            'istrait' => $object->isTraitClass($lc),
            'isenum' => $object->isEnumClassLc($lc),
            'isabstract' => self::objectIsAbstract($object, $lc, $classId),
            // Object_ has no isInternal bit — internals come from VM ClassEntry (#34067).
            'isinternal' => false,
            'isreadonly' => $object->isReadonlyClass($classId),
            default => false,
        };
    }

    private static function objectIsAbstract(Type\Object_ $object, string $lc, int $classId): bool
    {
        if ($object->isAbstractClassLc($lc)) {
            return true;
        }
        // Interfaces/traits/enums are not "abstract classes" in zim_ReflectionClass_isAbstract.
        if ($object->isInterfaceClassLc($lc)
            || $object->isTraitClass($lc)
            || $object->isEnumClassLc($lc)
        ) {
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

    private static function vmEntryMatchesKind(ClassEntry $entry, string $kindLc): bool
    {
        return match ($kindLc) {
            'isinterface' => ReflectionSupport::reflectionClassIsInterface($entry),
            'istrait' => ReflectionSupport::reflectionClassIsTrait($entry),
            'isenum' => $entry->isEnum,
            // php-src: interfaces report isAbstract=false via ce_flags; skip interface/trait/enum.
            'isabstract' => !$entry->isInterface && !$entry->isTrait && !$entry->isEnum
                && ($entry->isAbstract || [] !== $entry->abstractMethods),
            'isinternal' => $entry->isInternal,
            'isreadonly' => $entry->readonly,
            default => false,
        };
    }
}
