<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * settype() JIT lowering for compile-time type names (issue #3151).
 */
final class JitSettype
{
    public static function invoke(Context $context, JITVariable $var, JITVariable $typeArg): Value
    {
        $typeLit = JitStringArg::compileTimeLiteral($typeArg);
        if (null === $typeLit) {
            throw new \LogicException(
                'settype() with a non-constant type name is not supported for JIT in this compiler build'
            );
        }

        $destPtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $var)
        );

        switch (strtolower($typeLit)) {
            case 'integer':
            case 'int':
                self::convertInPlace($context, $destPtr, 'integer');
                break;
            case 'double':
            case 'float':
                self::convertInPlace($context, $destPtr, 'double');
                break;
            case 'bool':
            case 'boolean':
                self::convertInPlace($context, $destPtr, 'boolean');
                break;
            case 'string':
                self::convertInPlace($context, $destPtr, 'string');
                break;
            case 'array':
                self::convertInPlace($context, $destPtr, 'array');
                break;
            case 'null':
                $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
                break;
            case 'object':
                self::convertInPlaceToObject($context, $destPtr);
                break;
            case 'resource':
                throw new \ValueError('Cannot convert to resource type');
            default:
                throw new \ValueError('settype(): Argument #2 ($type) must be a valid type');
        }

        return $context->constantFromBool(true);
    }

    private static function convertInPlace(Context $context, Value $ptr, string $target): void
    {
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load($context->builder->structGep($ptr, $map['type']));
        $tag = 'st'.(string) spl_object_id($context);

        $stringBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_str');
        $afterStr = BasicBlockHelper::append($context, 'settype_'.$tag.'_after_str');
        $longBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_long');
        $dblBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_dbl');
        $boolBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_bool');
        $nullBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_null');
        $htBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_ht');
        $done = BasicBlockHelper::append($context, 'settype_'.$tag.'_done');

        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING, false)
            ),
            $stringBb,
            $afterStr
        );

        $context->builder->positionAtEnd($stringBb);
        self::emitTargetFromString($context, $ptr, $ptr, $target);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterStr);
        $afterHt = BasicBlockHelper::append($context, 'settype_'.$tag.'_after_ht');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_HASHTABLE, false)
            ),
            $htBb,
            $afterHt
        );

        $context->builder->positionAtEnd($htBb);
        self::emitTargetFromHashtable($context, $ptr, $ptr, $target);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHt);
        $afterDbl = BasicBlockHelper::append($context, 'settype_'.$tag.'_after_dbl');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
            ),
            $dblBb,
            $afterDbl
        );

        $context->builder->positionAtEnd($dblBb);
        self::emitTargetFromDouble($context, $ptr, $ptr, $target);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDbl);
        $afterBool = BasicBlockHelper::append($context, 'settype_'.$tag.'_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
            ),
            $boolBb,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBb);
        self::emitTargetFromLong($context, $ptr, $ptr, $target);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $objectBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj');
        $afterObj = BasicBlockHelper::append($context, 'settype_'.$tag.'_after_obj');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_OBJECT, false)
            ),
            $objectBb,
            $afterObj
        );

        $context->builder->positionAtEnd($objectBb);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $ptr);
        self::emitTargetFromEnumObject($context, $ptr, $objPtr, $target, $done, $afterObj);
        $context->builder->positionAtEnd($afterObj);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            ),
            $longBb,
            $nullBb
        );

        $context->builder->positionAtEnd($longBb);
        self::emitTargetFromLong($context, $ptr, $ptr, $target);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nullBb);
        self::emitTargetFromNull($context, $ptr, $target);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function emitTargetFromString(
        Context $context,
        Value $dest,
        Value $src,
        string $target
    ): void {
        switch ($target) {
            case 'integer':
                $parsed = self::strtolString($context, $src);
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $dest,
                    $context->builder->intCast($parsed, $context->getTypeFromString('int64'))
                );

                return;
            case 'double':
                $parsed = self::strtodString($context, $src);
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $dest,
                    $context->builder->fpCast($parsed, $context->getTypeFromString('double'))
                );

                return;
            case 'boolean':
                self::writeBoolFromString($context, $dest, $src);

                return;
            case 'string':
                $str = $context->builder->call($context->lookupFunction('__value__readString'), $src);
                $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
                $context->builder->call($context->lookupFunction('__value__writeString'), $dest, $owned);

                return;
            case 'array':
                self::wrapScalarInArray($context, $dest, $src);

                return;
        }
    }

    private static function emitTargetFromLong(
        Context $context,
        Value $dest,
        Value $src,
        string $target
    ): void {
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $src);
        switch ($target) {
            case 'integer':
                $context->builder->call($context->lookupFunction('__value__writeLong'), $dest, $long);

                return;
            case 'double':
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $dest,
                    $context->builder->sitofp($long, $context->getTypeFromString('double'))
                );

                return;
            case 'boolean':
                $truthy = $context->builder->icmp(Builder::INT_NE, $long, $long->typeOf()->constInt(0, false));
                self::writeBoolLong($context, $dest, $truthy);

                return;
            case 'string':
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $dest,
                    self::sprintfLong($context, $long)
                );

                return;
            case 'array':
                self::wrapScalarInArray($context, $dest, $src);

                return;
        }
    }

    private static function emitTargetFromDouble(
        Context $context,
        Value $dest,
        Value $src,
        string $target
    ): void {
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $src);
        switch ($target) {
            case 'integer':
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $dest,
                    $context->builder->fpToSi($dbl, $context->getTypeFromString('int64'))
                );

                return;
            case 'double':
                $context->builder->call($context->lookupFunction('__value__writeDouble'), $dest, $dbl);

                return;
            case 'boolean':
                $truthy = $context->builder->fcmp(Builder::REAL_ONE, $dbl, $dbl->typeOf()->constReal(0.0));
                self::writeBoolLong($context, $dest, $truthy);

                return;
            case 'string':
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $dest,
                    $context->builder->load($context->constantStringFromString(''))
                );

                return;
            case 'array':
                self::wrapScalarInArray($context, $dest, $src);

                return;
        }
    }

    private static function emitTargetFromHashtable(
        Context $context,
        Value $dest,
        Value $src,
        string $target
    ): void {
        $ht = $context->builder->call($context->lookupFunction('__value__readHashtable'), $src);
        if ('array' === $target) {
            $context->refcount->addref($ht);
            $context->builder->call($context->lookupFunction('__value__writeHashtable'), $dest, $ht);

            return;
        }
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $nonEmpty = $context->builder->icmp(
            Builder::INT_NE,
            $count,
            $count->typeOf()->constInt(0, false)
        );
        if ('integer' === $target) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $dest,
                $context->builder->select(
                    $nonEmpty,
                    $context->getTypeFromString('int64')->constInt(1, false),
                    $context->getTypeFromString('int64')->constInt(0, false)
                )
            );

            return;
        }
        if ('double' === $target) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $dest,
                $context->builder->select(
                    $nonEmpty,
                    $context->getTypeFromString('double')->constReal(1.0),
                    $context->getTypeFromString('double')->constReal(0.0)
                )
            );

            return;
        }
        if ('boolean' === $target) {
            self::writeBoolLong($context, $dest, $nonEmpty);

            return;
        }
        if ('string' === $target) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $dest,
                $context->builder->load($context->constantStringFromString('Array'))
            );
        }
    }

    private static function emitTargetFromNull(Context $context, Value $dest, string $target): void
    {
        switch ($target) {
            case 'integer':
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $dest,
                    $context->getTypeFromString('int64')->constInt(0, false)
                );

                return;
            case 'double':
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $dest,
                    $context->getTypeFromString('double')->constReal(0.0)
                );

                return;
            case 'boolean':
                self::writeBoolLong($context, $dest, $context->constantFromBool(false));

                return;
            case 'string':
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $dest,
                    $context->builder->load($context->constantStringFromString(''))
                );

                return;
            case 'array':
                $empty = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
                $context->builder->call($context->lookupFunction('__value__writeHashtable'), $dest, $empty);
        }
    }

    private static function wrapScalarInArray(Context $context, Value $dest, Value $src): void
    {
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htMap = $context->structFieldMap['__hashtable__'];
        $elemPtr = $context->builder->inBoundsGEP(
            $context->builder->structGep($ht, $htMap['values']),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        JitValueBox::copyIntoPointer(
            $context,
            $context->builder->pointerCast($elemPtr, $context->getTypeFromString('__value__*')),
            $src
        );
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $dest, $ht);
    }

    private static function writeBoolFromString(Context $context, Value $dest, Value $src): void
    {
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $src);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $nonEmpty = $context->builder->icmp(Builder::INT_NE, $len, $i64->constInt(0, false));
        $data = $context->builder->structGep($str, $map['value']);
        $isZero = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($data),
            $context->getTypeFromString('int8')->constInt((int) '0', false)
        );
        $onlyZero = $context->builder->and_(
            $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(1, false)),
            $isZero
        );
        self::writeBoolLong($context, $dest, $context->builder->and_($nonEmpty, $context->builder->not($onlyZero)));
    }

    private static function writeBoolLong(Context $context, Value $dest, Value $bool): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $dest,
            $context->builder->zExt($bool, $context->getTypeFromString('int64'))
        );
    }

    private static function strtolString(Context $context, Value $srcPtr): Value
    {
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $srcPtr);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $end = $context->builder->alloca($i8p, 1, 'settype_strtol_end');
        $context->builder->store($i8p->constNull(), $end);

        return $context->builder->call(
            $context->lookupFunction('strtol'),
            $context->builder->structGep($str, $map['value']),
            $end,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
    }

    private static function strtodString(Context $context, Value $srcPtr): Value
    {
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $srcPtr);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $end = $context->builder->alloca($i8p, 1, 'settype_strtod_end');
        $context->builder->store($i8p->constNull(), $end);

        return $context->builder->call(
            $context->lookupFunction('strtod'),
            $context->builder->structGep($str, $map['value']),
            $end
        );
    }

    private static function sprintfLong(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $long
        );

        return $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $context->builder->load($context->constantStringFromString('%lld')),
            $context->getTypeFromString('int64')->constInt(1, false),
            JitValueBox::pointer($context, $slot)
        );
    }

    /**
     * Zend settype() on enum case objects (#5643, ext/standard/type.c).
     */
    private static function emitTargetFromEnumObject(
        Context $context,
        Value $dest,
        Value $objPtr,
        string $target,
        BasicBlock $done,
        BasicBlock $nonEnumTarget
    ): void {
        $afterEnum = BasicBlockHelper::append($context, 'settype_enum_non_'.(string) spl_object_id($context));

        if ('object' === $target) {
            $matched = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToLong(
                $context,
                $objPtr,
                'settype',
                $afterEnum
            );
            if (null !== $matched) {
                $context->builder->branch($done);

                return;
            }
            $context->builder->positionAtEnd($afterEnum);
            $context->builder->branch($nonEnumTarget);

            return;
        }

        if ('boolean' === $target) {
            $matched = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToLong(
                $context,
                $objPtr,
                'settype',
                $afterEnum
            );
            if (null !== $matched) {
                self::writeBoolLong($context, $dest, $context->constantFromBool(true));
                $context->builder->branch($done);

                return;
            }
            $context->builder->positionAtEnd($afterEnum);
            $context->builder->branch($nonEnumTarget);

            return;
        }

        if ('integer' === $target) {
            $matched = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToLong(
                $context,
                $objPtr,
                'settype',
                $afterEnum
            );
            if (null !== $matched) {
                $context->builder->call($context->lookupFunction('__value__writeLong'), $dest, $matched);
                $context->builder->branch($done);

                return;
            }
            $context->builder->positionAtEnd($afterEnum);
            $context->builder->branch($nonEnumTarget);

            return;
        }

        if ('double' === $target) {
            $matched = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToDouble(
                $context,
                $objPtr,
                'settype',
                $afterEnum
            );
            if (null !== $matched) {
                $context->builder->call($context->lookupFunction('__value__writeDouble'), $dest, $matched);
                $context->builder->branch($done);

                return;
            }
            $context->builder->positionAtEnd($afterEnum);
            $context->builder->branch($nonEnumTarget);

            return;
        }

        if ('string' === $target) {
            if (JitScalarEnumCoerce::tryEmitObjectEnumCaseStringError(
                $context,
                $objPtr,
                'settype',
                $afterEnum
            )) {
                return;
            }
            $context->builder->positionAtEnd($afterEnum);
            $context->builder->branch($nonEnumTarget);

            return;
        }

        if ('array' === $target) {
            $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $objPtr);
            $boxed = JitGetObjectVars::invoke($context, $objVar, true);
            $ht = $context->builder->call($context->lookupFunction('__value__readHashtable'), $boxed);
            $context->builder->call($context->lookupFunction('__value__writeHashtable'), $dest, $ht);
            $context->builder->branch($done);

            return;
        }

        $context->builder->branch($nonEnumTarget);
    }

    /** Zend settype(..., 'object') in-place (#4254, ext/standard/type.c). */
    private static function convertInPlaceToObject(Context $context, Value $ptr): void
    {
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load($context->builder->structGep($ptr, $map['type']));
        $tag = 'sto'.(string) spl_object_id($context);

        $stringBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_str');
        $afterStr = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_after_str');
        $longBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_long');
        $dblBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_dbl');
        $boolBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_bool');
        $nullBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_null');
        $objectBb = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_obj');
        $done = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_done');

        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING, false)
            ),
            $stringBb,
            $afterStr
        );

        $context->builder->positionAtEnd($stringBb);
        self::writeStdClassWithScalarProperty($context, $ptr, $ptr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterStr);
        $afterDbl = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_after_dbl');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
            ),
            $dblBb,
            $afterDbl
        );

        $context->builder->positionAtEnd($dblBb);
        self::writeStdClassWithScalarProperty($context, $ptr, $ptr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDbl);
        $afterBool = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
            ),
            $boolBb,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBb);
        self::writeStdClassWithScalarProperty($context, $ptr, $ptr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $afterObj = BasicBlockHelper::append($context, 'settype_'.$tag.'_obj_after_obj');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_OBJECT, false)
            ),
            $objectBb,
            $afterObj
        );

        $context->builder->positionAtEnd($objectBb);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $ptr);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $objPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterObj);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            ),
            $longBb,
            $nullBb
        );

        $context->builder->positionAtEnd($longBb);
        self::writeStdClassWithScalarProperty($context, $ptr, $ptr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nullBb);
        self::writeEmptyStdClass($context, $ptr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function writeEmptyStdClass(Context $context, Value $dest): void
    {
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        $objVal = $object->allocate($classId);
        $object->markObjectConstructed($objVal);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $dest,
            $objVal
        );
    }

    private static function writeStdClassWithScalarProperty(
        Context $context,
        Value $dest,
        Value $scalarValuePtr
    ): void {
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        if (!$object->hasProperty($classId, 'scalar')) {
            $object->defineProperty($classId, 'scalar', JITVariable::TYPE_VALUE);
        }
        $objVal = $object->allocate($classId);
        $object->markObjectConstructed($objVal);
        $slot = $object->propertySlotFor($objVal, 'stdClass', 'scalar');
        $context->builder->call(
            $context->lookupFunction('__object__load_value_slot'),
            $slot,
            $scalarValuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $dest,
            $objVal
        );
    }

}
