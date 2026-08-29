<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCfg\Op\Type as CfgType;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Build ReflectionType objects under thin AOT (#28780). */
final class ReflectionTypeJitHelper
{
    /** @var list<string> */
    private static array $knownLabels = [];

    public static function noteLabel(string $label): void
    {
        $label = trim($label);
        if ('' === $label || in_array($label, self::$knownLabels, true)) {
            return;
        }
        self::$knownLabels[] = $label;
    }

    /** @return list<string> */
    public static function knownLabels(): array
    {
        return self::$knownLabels;
    }

    public static function resetKnownLabels(): void
    {
        self::$knownLabels = [];
    }

    /** Box a ReflectionType object (or null) as heap `__value__*` (safe across function calls). */
    public static function emitTypeFromLabelHeap(Context $context, string $label): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_type_from_label_heap');
        $valueTy = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueTy);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $cfg = ReflectionTypeSupport::cfgTypeFromLabel($label);
        if (null === $cfg) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                $heapPtr
            );

            return $heapPtr;
        }
        $obj = self::emitCfgTypeObject(
            $context,
            $cfg,
            ReflectionTypeSupport::cfgTypeStringForDump($cfg)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $heapPtr,
            $obj
        );

        return $heapPtr;
    }

    /** Box a ReflectionType object (or null) as `__value__*`. */
    public static function emitTypeFromLabel(Context $context, string $label): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_type_from_label');
        $slot = JitValueBox::alloc($context);
        $cfg = ReflectionTypeSupport::cfgTypeFromLabel($label);
        if (null === $cfg) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $obj = self::emitCfgTypeObject(
            $context,
            $cfg,
            ReflectionTypeSupport::cfgTypeStringForDump($cfg)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return JitValueBox::pointer($context, $slot);
    }

    /** Dispatch runtime label cstr against labels collected for this module. */
    public static function emitTypeFromLabelCstr(Context $context, Value $labelCstr): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_type_from_label_cstr');
        $labels = self::$knownLabels;
        $resultSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resultSlot)
        );
        if ([] === $labels) {
            return JitValueBox::pointer($context, $resultSlot);
        }

        LibcExtern::ensureStrcmpDecl($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $merge = BasicBlockHelper::append($context, 'refl_type_label_merge');
        $next = $context->builder->getInsertBlock();
        $seq = 0;

        foreach ($labels as $label) {
            $check = BasicBlockHelper::append($context, 'refl_type_label_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_type_label_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);
            $expected = $context->builder->pointerCast($context->constantFromString($label), $i8p);
            $eq = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $labelCstr,
                $expected
            );
            $ok = $context->builder->icmp(Builder::INT_EQ, $eq, $i32->constInt(0, false));
            $fallthrough = BasicBlockHelper::append($context, 'refl_type_label_next_'.$seq);
            $context->builder->branchIf($ok, $match, $fallthrough);
            $context->builder->positionAtEnd($match);
            $cfg = ReflectionTypeSupport::cfgTypeFromLabel($label);
            if (null !== $cfg) {
                $obj = self::emitCfgTypeObject(
                    $context,
                    $cfg,
                    ReflectionTypeSupport::cfgTypeStringForDump($cfg)
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    JitValueBox::pointer($context, $resultSlot),
                    $obj
                );
            }
            $context->builder->branch($merge);
            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_type_from_label_cstr_done');

        return JitValueBox::pointer($context, $resultSlot);
    }

    private static function emitCfgTypeObject(Context $context, CfgType $type, string $typeString): Value
    {
        if ($type instanceof CfgType\Union_) {
            // Thin AOT: composite labels are surfaced via typeString/__toString (#28780).
            return self::emitNamedObject($context, $typeString, $typeString, ReflectionTypeSupport::allowsNullFromCfg($type));
        }
        if ($type instanceof CfgType\Nullable) {
            $inner = $type->subtype;
            if (
                $inner instanceof CfgType\Literal
                || $inner instanceof CfgType\Reference
                || $inner instanceof CfgType\Mixed_
            ) {
                return self::emitNamedObject(
                    $context,
                    ReflectionTypeSupport::cfgTypeString($inner),
                    $typeString,
                    true
                );
            }

            return self::emitUnionObject($context, [$inner, new CfgType\Literal('null')], $typeString);
        }

        return self::emitNamedObject(
            $context,
            ReflectionTypeSupport::cfgTypeString($type),
            $typeString,
            ReflectionTypeSupport::allowsNullFromCfg($type)
        );
    }

    /**
     * @param list<CfgType> $members
     */
    private static function emitUnionObject(Context $context, array $members, string $typeString): Value
    {
        $classId = $context->type->object->lookup('ReflectionUnionType');
        $obj = $context->type->object->allocate($classId);
        ReflectionSetup::markConstructed($context, $obj);
        self::emitCommonTypeProps($context, $obj, 'ReflectionUnionType', $typeString, ReflectionTypeSupport::allowsNullFromCfg(
            new CfgType\Union_($members)
        ));
        $ht = HashTableHelper::alloc($context);
        $context->refcount->addref($ht);
        $i64 = $context->getTypeFromString('int64');
        $idx = 0;
        foreach ($members as $member) {
            $memberObj = self::emitCfgTypeObject(
                $context,
                $member,
                ReflectionTypeSupport::cfgTypeStringForDump($member)
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $i64->constInt($idx, false),
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $memberObj)
            );
            ++$idx;
        }
        $context->type->object->storeInstanceProperty(
            $obj,
            'ReflectionUnionType',
            ReflectionSupport::PROP_TYPE_MEMBERS,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht)
        );

        return $obj;
    }

    private static function emitNamedObject(
        Context $context,
        string $name,
        string $typeString,
        bool $allowsNull,
    ): Value {
        $classId = $context->type->object->lookup('ReflectionNamedType');
        $obj = $context->type->object->allocate($classId);
        ReflectionSetup::markConstructed($context, $obj);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cstr = $context->builder->pointerCast($context->constantFromString($name), $i8p);
        $len = $sizeT->constInt(\strlen($name), false);
        foreach (
            [
                ReflectionSupport::PROP_TYPE_NAME,
                ReflectionSupport::PROP_TYPE_STRING,
            ] as $prop
        ) {
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $obj,
                'ReflectionNamedType',
                $prop,
                $cstr,
                $len
            );
        }
        $true = $context->getTypeFromString('int1')->constInt(1, false);
        $false = $context->getTypeFromString('int1')->constInt(0, false);
        $context->type->object->storeInstanceProperty(
            $obj,
            'ReflectionNamedType',
            ReflectionSupport::PROP_TYPE_BUILTIN,
            new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                ReflectionTypeSupport::isBuiltinTypeNamePublic($name) ? $true : $false
            )
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            'ReflectionNamedType',
            ReflectionSupport::PROP_TYPE_ALLOWS_NULL,
            new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $allowsNull ? $true : $false)
        );
        $emptyHt = HashTableHelper::alloc($context);
        $context->refcount->addref($emptyHt);
        $context->type->object->storeInstanceProperty(
            $obj,
            'ReflectionNamedType',
            ReflectionSupport::PROP_TYPE_MEMBERS,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $emptyHt)
        );

        return $obj;
    }

    private static function emitCommonTypeProps(
        Context $context,
        Value $obj,
        string $className,
        string $typeString,
        bool $allowsNull,
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cstr = $context->builder->pointerCast($context->constantFromString($typeString), $i8p);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            $className,
            ReflectionSupport::PROP_TYPE_STRING,
            $cstr,
            $sizeT->constInt(\strlen($typeString), false)
        );
        $true = $context->getTypeFromString('int1')->constInt(1, false);
        $false = $context->getTypeFromString('int1')->constInt(0, false);
        $context->type->object->storeInstanceProperty(
            $obj,
            $className,
            ReflectionSupport::PROP_TYPE_ALLOWS_NULL,
            new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $allowsNull ? $true : $false)
        );
    }
}
