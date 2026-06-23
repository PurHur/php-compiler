<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering shared by intval()/floatval() for array/object operands (#10810, ext/standard/type.c).
 */
final class JitScalarTypeCoerce
{
    public static function hashtableToLong(Context $context, Value $htPtr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $htPtr
        );

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $count, $count->typeOf()->constInt(0, false)),
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
    }

    public static function hashtableToDouble(Context $context, Value $htPtr): Value
    {
        $double = $context->getTypeFromString('double');
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $htPtr
        );
        $nonEmpty = $context->builder->icmp(Builder::INT_NE, $count, $count->typeOf()->constInt(0, false));

        return $context->builder->select(
            $nonEmpty,
            $double->constReal(1.0),
            $double->constReal(0.0)
        );
    }

    /**
     * Zend intval/floatval on plain objects after enum dispatch — E_WARNING + 1 / 1.0 (#10810, type.c).
     *
     * @param 'int'|'float' $kind
     */
    public static function emitPlainObjectToScalar(Context $context, Value $objPtr, string $kind): Value
    {
        /** @var ObjectBuiltin $objectBuiltin */
        $objectBuiltin = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $tag = 'scalar_coerce_obj_'.(string) spl_object_id($context);
        $resultTy = 'int' === $kind ? $i64 : $double;

        $entries = [];
        foreach ($objectBuiltin->allClassNamesById() as $id => $name) {
            if ($objectBuiltin->isEnumClassLc(strtolower(ltrim($name, '\\')))) {
                continue;
            }
            $entries[(int) $id] = $name;
        }

        $emitScalar = static function (Context $context, string $className, string $kind) use ($i64, $double): Value {
            JitScalarEnumCoerce::emitObjectScalarWarning($context, $className, $kind);

            return 'int' === $kind ? $i64->constInt(1, false) : $double->constReal(1.0);
        };

        if ([] === $entries) {
            return $emitScalar($context, 'stdClass', $kind);
        }

        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $incoming = [];
        $ids = array_keys($entries);
        $lastIdx = \count($ids) - 1;
        $fallbackBlock = BasicBlockHelper::append($context, $tag.'_fallback');

        foreach ($ids as $idx => $id) {
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$id);
            $nextBlock = $idx === $lastIdx
                ? $fallbackBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$id);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $i64->constInt($id, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $incoming[] = [$emitScalar($context, $entries[$id], $kind), $context->builder->getInsertBlock()];
            $context->builder->branch($doneBlock);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($fallbackBlock);
        $incoming[] = [$emitScalar($context, 'stdClass', $kind), $context->builder->getInsertBlock()];
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($resultTy, $tag.'_phi');
        foreach ($incoming as [$val, $block]) {
            $phi->addIncoming($val, $block);
        }

        return $phi;
    }
}
