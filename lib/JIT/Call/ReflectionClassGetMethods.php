<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\ext\standard\VmReflection;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::getMethods() — JIT/AOT (#34107, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned NULL. Materialize a
 * packed list of seeded ReflectionMethod objects from Object_ method visibility
 * tables (skip parent-privates; declaring scope = original class, #22582 / #7191).
 *
 * php-src: zim_ReflectionClass_getMethods
 */
final class ReflectionClassGetMethods implements Call
{
    private static int $blockSeq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: optional ?int $filter = null — at most 1 user arg (#31033)
        $userArgCount = \count($args) - 1;
        if ($userArgCount < 0 || $userArgCount > 1) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::atMostUserArgCountMessage(
                    'ReflectionClass::getMethods',
                    1,
                    max(0, $userArgCount)
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getmethods_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $filter = 0;
        if (1 === $userArgCount) {
            $filterArg = $args[1];
            if ($filterArg->isNullConstant || $filterArg->isOptionalOmittedNamedArg) {
                $filter = 0;
            } elseif (null !== $filterArg->compileTimeLong) {
                $filter = (int) $filterArg->compileTimeLong;
            } else {
                // Dynamic filter: treat as 0 (all) for thin AOT — Done-when covers
                // omitted / null / constant filter (#34107).
                $filter = 0;
            }
        }

        $classIdVal = ReflectionClassNewLazyProxy::loadClassIdFromLazyFactoryArg(
            $context,
            $args[0]
        );

        return $this->dispatchByClassId($context, $classIdVal, $filter);
    }

    private function dispatchByClassId(Context $context, Value $classId, int $filter): Value
    {
        $object = $context->type->object;
        /** @var list<int> $ids */
        $ids = [];
        foreach ($object->allClassNamesById() as $id => $name) {
            if (!$object->hasUserDeclaredClass($name)) {
                continue;
            }
            $ids[] = (int) $id;
        }

        if ([] === $ids) {
            return self::wrapHashTable($context, HashTableHelper::alloc($context));
        }

        $tag = 'gm'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'refl_gm_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'refl_gm_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );

        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'refl_gm_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'refl_gm_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = self::emitMethodsArrayForClassId($context, $object, $id, $filter);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $empty = self::wrapHashTable($context, HashTableHelper::alloc($context));
        $context->builder->store(
            JitValueBox::coerceToValuePtrForStore($context, $empty),
            $resultSlot
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @return list<array{display: string, declaring: string}>
     */
    public static function collectMethodsForClassId(Object_ $object, int $reflectedId, int $filter): array
    {
        $reflectedLc = $object->classLcForId($reflectedId);
        if (null === $reflectedLc) {
            return [];
        }

        // Child-first chain (Zend getMethods order).
        $chainIds = [];
        if ($object->isInterfaceClassLc($reflectedLc)) {
            foreach ($object->interfaceHierarchyLc($reflectedLc) as $lc) {
                $id = $object->classIdForLowerName($lc);
                if (null !== $id) {
                    $chainIds[] = $id;
                }
            }
        } else {
            $currentLc = $reflectedLc;
            while (null !== $currentLc) {
                $id = $object->classIdForLowerName($currentLc);
                if (null !== $id) {
                    $chainIds[] = $id;
                }
                $currentLc = $object->parentClassLc($currentLc);
            }
        }

        /** @var array<string, array{display: string, declaring: string}> $byLc */
        $byLc = [];
        foreach ($chainIds as $classId) {
            $className = $object->classNameForId($classId);
            foreach ($object->declaredMethodNames($classId) as $methodLc) {
                if (isset($byLc[$methodLc])) {
                    continue;
                }
                // Skip visibility slots copied from parents without a local declaration
                // (inheritMethodVisibilityFromParent does not copy methodDisplayNames).
                $parentLc = $object->parentClassLc($object->classLcForId($classId) ?? '');
                $parentId = null !== $parentLc ? $object->classIdForLowerName($parentLc) : null;
                $hasDisplay = null !== ($object->methodDisplayName($classId, $methodLc));
                $parentHas = null !== $parentId
                    && \in_array($methodLc, $object->declaredMethodNames($parentId), true);
                if (!$hasDisplay && $parentHas) {
                    continue;
                }

                $vis = $object->methodVisibility($classId, $methodLc);
                if (!VmReflection::methodMatchesReflectionFilter($vis, $filter)) {
                    continue;
                }
                // php-src add_reflection_method_sub: parent-private hidden on child (#7191).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $classId !== $reflectedId) {
                    continue;
                }

                $display = $object->methodDisplayName($classId, $methodLc) ?? $methodLc;
                $byLc[$methodLc] = [
                    'display' => $display,
                    'declaring' => $className,
                ];
            }
        }

        return array_values($byLc);
    }

    private static function emitMethodsArrayForClassId(
        Context $context,
        Object_ $object,
        int $classId,
        int $filter
    ): Value {
        $specs = self::collectMethodsForClassId($object, $classId, $filter);
        $ht = HashTableHelper::alloc($context);
        $n = \count($specs);
        if ($n > 0) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $context->constantFromInteger($n, 'size_t')
            );
        }

        $rmClassId = $object->lookup('ReflectionMethod');
        $strMap = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ($specs as $i => $spec) {
            $rmObj = $object->allocate($rmClassId);

            $classStr = $context->builder->load(
                $context->constantStringFromString($spec['declaring'])
            );
            $classCstr = $context->builder->pointerCast(
                $context->builder->structGep($classStr, $strMap['value']),
                $i8p
            );
            $classLen = $context->builder->zExt(
                $context->builder->load(
                    $context->builder->structGep($classStr, $strMap['length'])
                ),
                $sizeT
            );
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $rmObj,
                'ReflectionMethod',
                'class',
                $classCstr,
                $classLen
            );

            $nameStr = $context->builder->load(
                $context->constantStringFromString($spec['display'])
            );
            $nameCstr = $context->builder->pointerCast(
                $context->builder->structGep($nameStr, $strMap['value']),
                $i8p
            );
            $nameLen = $context->builder->zExt(
                $context->builder->load(
                    $context->builder->structGep($nameStr, $strMap['length'])
                ),
                $sizeT
            );
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $rmObj,
                'ReflectionMethod',
                'name',
                $nameCstr,
                $nameLen
            );
            ReflectionSetup::markConstructed($context, $rmObj);

            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $context->constantFromInteger($i, 'size_t'),
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rmObj)
            );
        }

        return self::wrapHashTable($context, $ht);
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
