<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\spl\ArrayIteratorBuiltin;
use PHPCompiler\ext\spl\ArrayObjectBuiltin;
use PHPCompiler\ext\spl\RecursiveArrayIteratorBuiltin;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Object / class-id literal arms for property_exists() JIT (#31966, #32688, #33068). */
final class JitPropertyExistsObject
{
    private static int $blockSeq = 0;

    /**
     * Runtime class-string + literal property: walk LLVM object table then autoload (#35788 / #32701).
     *
     * NestedJIT VmReflection misses AOT user classes (#31966).
     */
    public static function existsForRuntimeClassNameLiteralProperty(
        Context $context,
        Value $classStr,
        JITVariable $classArg,
        JITVariable $propertyArg,
        string $property
    ): Value {
        \PHPCompiler\JIT\Builtin\StringCaseCompare::ensureStrcasecmpLinked($context);
        $i1 = $context->getTypeFromString('int1');
        $matched = $i1->constInt(0, false);
        $exists = $i1->constInt(0, false);
        $object = $context->type->object;
        $classData = self::stringDataPtr($context, $classStr);
        foreach ($object->allClassNamesById() as $id => $className) {
            $lit = $context->builder->load($context->constantStringFromString((string) $className));
            $cmp = $context->builder->call(
                $context->lookupFunction(\PHPCompiler\JIT\Builtin\StringCaseCompare::ABI_STRCASECMP),
                $classData,
                self::stringDataPtr($context, $lit)
            );
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $context->constantFromInteger(0, 'int32')
            );
            $matched = $context->builder->or($matched, $isMatch);
            $classExists = $object->propertyExistsFromScope($id, $property)
                ? $i1->constInt(1, false)
                : $i1->constInt(0, false);
            if ('name' === $property || 'value' === $property) {
                $enumExists = $i1->constInt(0, false);
                if ($object->isEnumClassId($id)) {
                    if ('name' === $property) {
                        $enumExists = $i1->constInt(1, false);
                    } elseif ($object->enumHasBacking($id)) {
                        $enumExists = $i1->constInt(1, false);
                    }
                }
                $classExists = $context->builder->or($classExists, $enumExists);
            }
            $exists = $context->builder->select($isMatch, $classExists, $exists);
        }
        $tag = 'r'.(string) self::$blockSeq++;
        $knownBlock = BasicBlockHelper::append($context, 'prop_exists_runtime_known_'.$tag);
        $autoloadBlock = BasicBlockHelper::append($context, 'prop_exists_runtime_autoload_'.$tag);
        $mergeBlock = BasicBlockHelper::append($context, 'prop_exists_runtime_merge_'.$tag);
        $context->builder->branchIf($matched, $knownBlock, $autoloadBlock);

        $context->builder->positionAtEnd($knownBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($autoloadBlock);
        $helperResult = JitPropertyExists::routeThroughPhpHelper($context, $classArg, $propertyArg);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($exists, $knownBlock);
        $phi->addIncoming($helperResult, $autoloadBlock);

        return $phi;
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    public static function forCompleteObject(
        Context $context,
        JITVariable $objectArg,
        JITVariable $propertyArg,
        ?string $propLiteral,
        Value $classId
    ): Value {
        if (null !== $propLiteral) {
            // ARRAY_AS_PROPS flags are runtime — fold only via PHP helper (#31039).
            $isSplArray = self::isSplArrayStorageClassId($context, $classId);
            $splBlock = BasicBlockHelper::append($context, 'prop_exists_spl_array');
            $normBlock = BasicBlockHelper::append($context, 'prop_exists_not_spl_array');
            $mergeBlock = BasicBlockHelper::append($context, 'prop_exists_spl_merge');
            $context->builder->branchIf($isSplArray, $splBlock, $normBlock);

            $context->builder->positionAtEnd($splBlock);
            // Thin AOT ArrayObject uses `__spl_ht` + `__flags`, not VM ObjectEntry — NestedJIT
            // PropertyExistsJitHelper always returns false (#33068). Probe HT when ARRAY_AS_PROPS.
            $objPtr = $context->helper->loadValue($objectArg);
            $splResult = self::compileSplArrayPropertyExists(
                $context,
                $objPtr,
                $classId,
                $propLiteral
            );
            $splEnd = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($normBlock);
            $normResult = self::forCompleteObjectLiteralProperty($context, $classId, $propLiteral);
            $normEnd = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($mergeBlock);
            $phi = $context->builder->phi($splResult->typeOf());
            $phi->addIncoming($splResult, $splEnd);
            $phi->addIncoming($normResult, $normEnd);

            return $phi;
        }

        return JitPropertyExists::routeObjectThroughPhpHelper($context, $objectArg, $propertyArg);
    }

    private static function forCompleteObjectLiteralProperty(
        Context $context,
        Value $classId,
        string $propLiteral
    ): Value {
        // Enum pseudo-props name/value are case-sensitive (#23532).
        if ('name' === $propLiteral || 'value' === $propLiteral) {
            $enumExists = self::existsForEnumCasePropertyLiteral($context, $classId, $propLiteral);
            $regularExists = self::existsForClassIdLiteralProperty($context, $classId, $propLiteral);

            return $context->builder->or($enumExists, $regularExists);
        }

        return self::existsForClassIdLiteralProperty($context, $classId, $propLiteral);
    }

    private static function existsForEnumCasePropertyLiteral(
        Context $context,
        Value $classId,
        string $propLc
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $exists = $i1->constInt(0, false);
        foreach ($object->allClassNamesById() as $id => $className) {
            if (!$object->isEnumClassId($id)) {
                continue;
            }
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $classExists = $i1->constInt(0, false);
            if ('name' === $propLc) {
                $classExists = $i1->constInt(1, false);
            } elseif ('value' === $propLc && $object->enumHasBacking($id)) {
                $classExists = $i1->constInt(1, false);
            }
            $exists = $context->builder->select($isClass, $classExists, $exists);
        }

        return $exists;
    }

    private static function isSplArrayStorageClassId(Context $context, Value $classId): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $isSpl = $i1->constInt(0, false);
        foreach ([
            ArrayObjectBuiltin::CLASS_LC,
            ArrayIteratorBuiltin::CLASS_LC,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
        ] as $classLc) {
            $id = $object->classIdByName($classLc);
            if (null === $id) {
                $id = $object->classIdForLowerName($classLc);
            }
            if (null === $id) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $isSpl = $context->builder->or($isSpl, $match);
        }

        return $isSpl;
    }

    /**
     * Runtime class-id dispatch → ARRAY_AS_PROPS HT probe with the correct layout class (#33068).
     *
     * @return Value i1
     */
    private static function compileSplArrayPropertyExists(
        Context $context,
        Value $objPtr,
        Value $classId,
        string $propLiteral
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $fn = $context->builder->getInsertBlock()->getParent();
        $mergeBb = $fn->appendBasicBlock('ao_pex_class_merge');
        $incomings = [];
        $nextBb = null;
        $classes = [
            'ArrayObject' => ArrayObjectBuiltin::CLASS_LC,
            'ArrayIterator' => ArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator' => RecursiveArrayIteratorBuiltin::CLASS_LC,
        ];
        $remaining = [];
        foreach ($classes as $display => $lc) {
            $id = $object->classIdByName($lc) ?? $object->classIdForLowerName($lc);
            if (null !== $id) {
                $remaining[] = [$display, $id];
            }
        }
        if ([] === $remaining) {
            return $i1->constInt(0, false);
        }
        foreach ($remaining as $idx => [$display, $id]) {
            $isLast = $idx === count($remaining) - 1;
            $matchBb = $fn->appendBasicBlock('ao_pex_class_'.$display);
            if (!$isLast) {
                $nextBb = $fn->appendBasicBlock('ao_pex_class_next_'.$idx);
                $match = $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $context->constantFromInteger($id, 'int64')
                );
                $context->builder->branchIf($match, $matchBb, $nextBb);
            } else {
                // Last arm — no further compare (already in isSplArray block).
                $context->builder->branch($matchBb);
            }
            $context->builder->positionAtEnd($matchBb);
            $exists = \PHPCompiler\VM\ArrayObjectJitHelper::compilePropertyExists(
                $context,
                $objPtr,
                $display,
                $propLiteral
            );
            $existsEnd = $context->builder->getInsertBlock();
            $incomings[] = [$exists, $existsEnd];
            $context->builder->branch($mergeBb);
            if (!$isLast && null !== $nextBb) {
                $context->builder->positionAtEnd($nextBb);
            }
        }
        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        foreach ($incomings as [$val, $bb]) {
            $phi->addIncoming($val, $bb);
        }

        return $phi;
    }

    private static function existsForClassIdLiteralProperty(
        Context $context,
        Value $classId,
        string $property
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $exists = $i1->constInt(0, false);
        foreach ($object->allClassNamesById() as $id => $className) {
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $classExists = $object->propertyExistsFromScope($id, $property)
                ? $i1->constInt(1, false)
                : $i1->constInt(0, false);
            $exists = $context->builder->select($isClass, $classExists, $exists);
        }

        return $exists;
    }
}
