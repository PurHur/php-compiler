<?php

declare(strict_types=1);

/**
 * LLVM helpers for packed-list __hashtable__ (stdlib array builtins).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\string_trim;
use PHPCompiler\JIT\Builtin\CallUnpackRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class HashTableHelper
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * Unbox a {@see Variable::TYPE_VALUE} slot to a __hashtable__* (array_push, count, isset patterns).
     */
    public static function readHashtableFromValueBox(Context $context, Variable $container): Value
    {
        if (Variable::TYPE_VALUE !== $container->type && !JitValueBox::isValueOperand($container)) {
            throw new \LogicException('readHashtableFromValueBox requires TYPE_VALUE');
        }

        return self::ensureHashtablePointer($context, $container);
    }

    /** Materialize empty hashtables for null boxed arrays and object properties (#1086). */
    public static function ensureHashtablePointer(Context $context, Variable $array): Value
    {
        if (null !== $array->objectPropertySlot && Variable::TYPE_VALUE === ($array->objectPropertyType ?? null)) {
            $voidPtr = $context->getTypeFromString('void*');
            $slot = $array->objectPropertySlot;
            $loaded = $context->builder->pointerCast(
                $context->builder->load($slot),
                $voidPtr
            );
            $slotEmpty = $context->builder->icmp(
                Builder::INT_EQ,
                $loaded,
                $voidPtr->constNull()
            );
            $initSlot = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_init');
            $useSlot = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_use');
            $done = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_done');
            $context->builder->branchIf($slotEmpty, $initSlot, $useSlot);

            $context->builder->positionAtEnd($initSlot);
            $newHt = self::alloc($context);
            $emptyHt = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $newHt
            );
            $context->type->object->propertyStore($slot, $emptyHt, Variable::TYPE_VALUE);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($useSlot);
            $valPtr = $context->builder->pointerCast(
                $loaded,
                $context->getTypeFromString('__value__*')
            );
            $existing = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $valPtr
            );
            $needsInit = $context->builder->icmp(
                Builder::INT_EQ,
                $existing,
                $existing->typeOf()->constNull()
            );
            $initBox = BasicBlockHelper::append($context, 'ht_ensure_prop_box_init');
            $ready = BasicBlockHelper::append($context, 'ht_ensure_prop_box_ready');
            $context->builder->branchIf($needsInit, $initBox, $ready);

            $context->builder->positionAtEnd($initBox);
            $boxHt = self::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $valPtr,
                $boxHt
            );
            $context->builder->branch($done);

            $context->builder->positionAtEnd($ready);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($done);
            $htPhi = $context->builder->phi($newHt->typeOf());
            $htPhi->addIncoming($newHt, $initSlot);
            $htPhi->addIncoming($boxHt, $initBox);
            $htPhi->addIncoming($existing, $ready);

            return $htPhi;
        }

        $valPtr = JitValueBox::valuePtrFromVariable($context, $array);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $ht,
            $ht->typeOf()->constNull()
        );
        $init = BasicBlockHelper::append($context, 'ht_ensure_box_init');
        $ready = BasicBlockHelper::append($context, 'ht_ensure_box_ready');
        $done = BasicBlockHelper::append($context, 'ht_ensure_box_done');
        $context->builder->branchIf($isNull, $init, $ready);

        $context->builder->positionAtEnd($init);
        $newHt = self::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valPtr,
            $newHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($ready);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($ht->typeOf());
        $result->addIncoming($newHt, $init);
        $result->addIncoming($ht, $ready);

        return $result;
    }


    /**
     * HashTable view without objectPropertySlot — isset()/dim on $this->props['k'] (#764).
     */
    public static function asDetachedHashtable(Context $context, Variable $container): Variable
    {
        if (null === $container->objectPropertySlot) {
            return $container;
        }

        $detached = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            self::loadHashtablePointer($context, $container)
        );
        $detached->superglobalName = $container->superglobalName;

        return $detached;
    }

    /**
     * Load a native {@see __hashtable__*} from a boxed or direct array variable (#107).
     */

    /** Persist in-place hashtable mutations on native/boxed array operands (#1086). */
    public static function storeHashtableInArrayVariable(Context $context, Variable $array, Value $ht): void
    {
        if (0 !== ($array->type & Variable::IS_NATIVE_ARRAY)) {
            if (Variable::KIND_VARIABLE !== $array->kind) {
                return;
            }
            $boxed = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                JitValueBox::pointer($context, $boxed),
                $ht
            );
            $voidPtr = $context->getTypeFromString('void*');
            $context->builder->store(
                $context->builder->pointerCast(JitValueBox::pointer($context, $boxed), $voidPtr),
                $array->value
            );
            $array->type = Variable::TYPE_VALUE;
            $array->valueBoxHashtable = true;

            return;
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            if (null !== $array->objectPropertySlot) {
                $valPtr = $context->builder->pointerCast(
                    $context->builder->load($array->objectPropertySlot),
                    $context->getTypeFromString('__value__*')
                );
            } else {
                $valPtr = JitValueBox::valuePtrFromVariable($context, $array);
            }
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $valPtr,
                $ht
            );
        }
    }

    public static function loadHashtablePointer(Context $context, Variable $array): Value
    {
        if (null !== $array->objectPropertySlot) {
            if (Variable::TYPE_HASHTABLE === ($array->objectPropertyType ?? null)) {
                return $context->builder->pointerCast(
                    $context->builder->load($array->objectPropertySlot),
                    $context->getTypeFromString('__hashtable__*')
                );
            }

            return self::ensureHashtablePointer($context, $array);
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->helper->loadValue($array);
        }
        if (Variable::TYPE_VALUE === $array->type || $array->valueBoxHashtable) {
            return self::ensureHashtablePointer($context, $array);
        }

        throw new \LogicException(
            'Array offset access requires hashtable or boxed array, got '
            .Variable::getStringType($array->type)
        );
    }

    /**
     * Stable string key for SplObjectStorage object offsets (pointer identity, issue #601).
     */
    public static function objectPointerAsStringKey(Context $context, Variable $keyObject): Variable
    {
        if (Variable::TYPE_OBJECT !== $keyObject->type) {
            throw new \LogicException('SplObjectStorage keys must be objects in this compiler build');
        }
        $objPtr = $context->helper->loadValue($keyObject);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $ptrInt = $context->builder->ptrToInt($objPtr, $sizeT);
        $buf = $context->builder->alloca($context->getTypeFromString('int8'), $sizeT->constInt(32, false), 'spl_key_buf');
        $bufC = $context->builder->pointerCast($buf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%zu'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $ptrInt);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $context->getTypeFromString('int64');
        $lenForInit = $len->typeOf() === $lenI64
            ? $len
            : $context->builder->zExt($len, $lenI64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenForInit,
            $bufC
        );

        return new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $str
        );
    }

    public static function alloc(Context $context): Value
    {
        return $context->builder->call($context->lookupFunction('__hashtable__alloc'));
    }

    /** Empty packed list for variadic recv with zero trailing args (issue #197). */
    public static function emptyVariable(Context $context): Variable
    {
        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            self::alloc($context)
        );
    }

    public static function variableFromVmHashTable(Context $context, \PHPCompiler\VM\HashTable $table): Variable
    {
        $ht = self::alloc($context);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $setStringKey = $context->lookupFunction('__hashtable__setStringKeyString');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $resolved = $valueVar->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $keyVar->type) {
                $idx = $context->constantFromInteger($keyVar->toInt(), 'size_t');
                if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $resolved->type) {
                    $context->builder->call(
                        $setLong,
                        $ht,
                        $idx,
                        $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_STRING === $resolved->type) {
                    $str = $context->builder->load(
                        $context->constantStringFromString($resolved->toString())
                    );
                    $context->builder->call($setStringAt, $ht, $idx, $str);
                } elseif (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $resolved->type) {
                    $context->builder->call(
                        $setLong,
                        $ht,
                        $idx,
                        $context->getTypeFromString('int64')->constInt($resolved->toBool() ? 1 : 0, false)
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_FLOAT === $resolved->type) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setDoubleAt'),
                        $ht,
                        $idx,
                        $context->constantFromFloat($resolved->toFloat())
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_NULL === $resolved->type) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setNullAt'),
                        $ht,
                        $idx
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_ARRAY === $resolved->type) {
                    self::setAtIndex(
                        $context,
                        $ht,
                        $idx,
                        self::variableFromVmHashTable($context, $resolved->toArray())
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_OBJECT === $resolved->type
                    || \PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $resolved->type) {
                    $context->type->object->embedClassConstArrayVmElementAtIndex($context, $ht, $idx, $resolved);
                } else {
                    throw new \LogicException(
                        'Unsupported class constant array element type for JIT: '
                        .Variable::getStringType(Variable::fromVMVariable($resolved->type))
                    );
                }

                continue;
            }
            if (\PHPCompiler\VM\Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $context->builder->load(
                $context->constantStringFromString($keyVar->toString())
            );
            if (\PHPCompiler\VM\Variable::TYPE_STRING === $resolved->type) {
                $str = $context->builder->load(
                    $context->constantStringFromString($resolved->toString())
                );
                $context->builder->call($setStringKey, $ht, $key, $str);
            } elseif (\PHPCompiler\VM\Variable::TYPE_INTEGER === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $key,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyBool'),
                    $ht,
                    $key,
                    $context->getTypeFromString('bool')->constInt($resolved->toBool() ? 1 : 0, false)
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_FLOAT === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyDouble'),
                    $ht,
                    $key,
                    $context->constantFromFloat($resolved->toFloat())
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_ARRAY === $resolved->type) {
                self::setAtKeyCoercingNumericString(
                    $context,
                    $ht,
                    $key,
                    self::variableFromVmHashTable($context, $resolved->toArray())
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_NULL === $resolved->type) {
                self::setAtKeyCoercingNumericString(
                    $context,
                    $ht,
                    $key,
                    new Variable(
                        $context,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $context->getTypeFromString('__value__*')->constNull()
                    )
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_OBJECT === $resolved->type
                || \PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $resolved->type) {
                $context->type->object->embedClassConstArrayVmElementAtStringKey($context, $ht, $key, $resolved);
            } else {
                throw new \LogicException(
                    'Unsupported class constant array element type for JIT: '
                    .Variable::getStringType(Variable::fromVMVariable($resolved->type))
                );
            }
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    /**
     * Pack JIT call/recv arguments into a list hashtable (issue #197).
     *
     * @param list<Variable> $vars
     */
    public static function packVariables(Context $context, array $vars): Variable
    {
        $ht = self::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        foreach ($vars as $index => $var) {
            if (!$var instanceof Variable) {
                continue;
            }
            self::setAtIndex($context, $ht, $i64->constInt($index, false), $var);
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    public static function buildIntegerRange(
        Context $context,
        Value $start,
        Value $end,
        Value $step
    ): Value {
        $ht = self::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = $context->builder->alloca($i64, 1, 'range_i');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'range_idx');
        $context->builder->store($start, $iSlot);
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $idxSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $done = BasicBlockHelper::append($context, 'range_done');
        $loopHead = BasicBlockHelper::append($context, 'range_head');
        $loopBody = BasicBlockHelper::append($context, 'range_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $stepPos = $context->builder->icmp(Builder::INT_SGT, $step, $i64->constInt(0, false));
        $condPos = $context->builder->icmp(Builder::INT_SLE, $i, $end);
        $condNeg = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $inRange = $context->builder->select($stepPos, $condPos, $condNeg);
        $context->builder->branchIf($inRange, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setLong, $ht, $idx, $i);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $step),
            $iSlot
        );
        $one = $sizeT->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        return $ht;
    }

    public static function buildArrayFill(
        Context $context,
        Value $startIndex,
        Value $count,
        Variable $value
    ): Value {
        $tag = 'af'.(string) self::nextSeq();
        $ht = self::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $iSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $done = BasicBlockHelper::append($context, 'fill_done_'.$tag);
        $loopHead = BasicBlockHelper::append($context, 'fill_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'fill_body_'.$tag);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $count);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $index = $context->builder->addNoSignedWrap($startIndex, $i);
        switch ($value->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $setLong,
                    $ht,
                    $index,
                    $context->helper->loadValue($value)
                );
                break;
            case Variable::TYPE_STRING:
                $str = $context->helper->loadValue($value);
                $strMap = $context->structFieldMap['__string__'];
                $hayPtr = $context->builder->structGep($str, $strMap['value']);
                $len = $context->builder->load(
                    $context->builder->structGep($str, $strMap['length'])
                );
                $owned = string_trim::jitCopySlice(
                    $context,
                    $str,
                    $hayPtr,
                    $context->getTypeFromString('int64')->constInt(0, false),
                    $len,
                    'fill_'.$tag
                );
                $context->builder->call(
                    $setString,
                    $ht,
                    $index,
                    $owned
                );
                break;
            default:
                throw new \LogicException(
                    'array_fill() value type not supported for JIT: '
                    .Variable::getStringType($value->type)
                );
        }
        $one = $sizeT->constInt(1, false);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        BasicBlockHelper::branchToFreshContinue($context, 'fill_continue_'.$tag);

        return $ht;
    }

    public static function listEntryPointer(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    public static function offsetUnset(Context $context, Variable $container, Variable $dim): void
    {
        $ht = self::loadHashtablePointer($context, $container);
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->helper->loadValue($dim);
            $context->builder->call(
                $context->lookupFunction('__hashtable__unsetLongAt'),
                $ht,
                $index
            );

            return;
        }
        if (Variable::TYPE_STRING === $dim->type) {
            $key = $context->helper->loadValue($dim);
            $context->builder->call(
                $context->lookupFunction('__hashtable__unsetStringKey'),
                $ht,
                $key
            );

            return;
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            self::unsetValueBoxKey($context, $ht, $dim);

            return;
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            self::emitIllegalOffsetType($context, 'Illegal offset type in unset');

            return;
        }
        throw new \LogicException('unset() array offset requires int or string index in this compiler build');
    }

    /**
     * isset() / empty() offset check for string, int, object, or boxed keys (issue #86).
     */
    public static function offsetIsSetDim(Context $context, Value $ht, Variable $dim): Value
    {
        if (Variable::TYPE_NULL === $dim->type) {
            $emptyKey = $context->builder->load($context->constantStringFromString(''));

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                $emptyKey
            );
        }
        if (Variable::TYPE_STRING === $dim->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                $context->helper->loadValue($dim)
            );
        }
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($dim),
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            self::emitIllegalOffsetType($context, 'Illegal offset type in isset or empty');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            return self::offsetIsSetValueBoxKey($context, $ht, $dim);
        }

        throw new \LogicException(
            'isset() on HashTable arrays only supports integer or string indices in this compiler build'
        );
    }

    /**
     * Read an element into a stack {@see __value__} slot (string/int/object/boxed keys; issue #86).
     *
     * @param string|null $superglobalName When set, string keys use superglobal-safe read (issue #273).
     */
    public static function readDimToValueBox(
        Context $context,
        Value $ht,
        Variable $dim,
        ?string $superglobalName = null
    ): Variable {
        if (Variable::TYPE_STRING === $dim->type) {
            $key = $context->helper->loadValue($dim);
            if (null !== $superglobalName) {
                return self::readSuperglobalStringKeyToValueBox($context, $ht, $key);
            }

            return self::readStringKeyToValueBox($context, $ht, $key);
        }
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($dim),
                $context->getTypeFromString('size_t')
            );

            return self::readIndexedToValueBox($context, $ht, $index);
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            return self::readObjectKeyToValueBox($context, $ht, $context->helper->loadValue($dim));
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            return self::readValueBoxKeyToValueBox($context, $ht, $dim, $superglobalName);
        }

        throw new \LogicException(
            'Array fetch only supports integer or string indices in this compiler build'
        );
    }

    private static function valuePtrFromDim(Context $context, Variable $dim): Value
    {
        if (Variable::TYPE_VALUE !== $dim->type) {
            throw new \LogicException('valuePtrFromDim requires TYPE_VALUE');
        }

        return Variable::KIND_VARIABLE === $dim->kind
            ? JitValueBox::pointer($context, $dim->value)
            : $context->helper->loadValue($dim);
    }

    private static function offsetIsSetValueBoxKey(Context $context, Value $ht, Variable $dim): Value
    {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_isset_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_isset_vk_long');
        $objectBlock = $fn->appendBasicBlock('ht_isset_vk_obj');
        $falseBlock = $fn->appendBasicBlock('ht_isset_vk_false');
        $merge = $fn->appendBasicBlock('ht_isset_vk_merge');
        $afterString = $fn->appendBasicBlock('ht_isset_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_isset_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        $longResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $falseBlock
        );
        $context->builder->positionAtEnd($objectBlock);
        self::emitIllegalOffsetType($context, 'Illegal offset type in isset or empty');
        $objResult = $context->getTypeFromString('int1')->constInt(0, false);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($strResult, $stringBlock);
        $phi->addIncoming($longResult, $longBlock);
        $phi->addIncoming($objResult, $objectBlock);
        $phi->addIncoming($i1->constInt(0, false), $falseBlock);

        return $phi;
    }

    private static function readValueBoxKeyToValueBox(
        Context $context,
        Value $ht,
        Variable $dim,
        ?string $superglobalName
    ): Variable {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_read_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_read_vk_long');
        $objectBlock = $fn->appendBasicBlock('ht_read_vk_obj');
        $nullBlock = $fn->appendBasicBlock('ht_read_vk_null');
        $merge = $fn->appendBasicBlock('ht_read_vk_merge');
        $afterString = $fn->appendBasicBlock('ht_read_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strBox = null !== $superglobalName
            ? self::readSuperglobalStringKeyToValueBox($context, $ht, $keyStr)
            : self::readStringKeyToValueBox($context, $ht, $keyStr);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $strBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_read_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $context->getTypeFromString('size_t')
        );
        $longBox = self::readIndexedToValueBox($context, $ht, $index);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $longBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $nullBlock
        );
        $context->builder->positionAtEnd($objectBlock);
        $keyObj = $context->builder->call($context->lookupFunction('__value__readObject'), $valPtr);
        $objBox = self::readObjectKeyToValueBox($context, $ht, $keyObj);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $objBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function unsetValueBoxKey(Context $context, Value $ht, Variable $dim): void
    {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_unset_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_unset_vk_long');
        $illegalBlock = $fn->appendBasicBlock('ht_unset_vk_illegal');
        $done = $fn->appendBasicBlock('ht_unset_vk_done');
        $afterString = $fn->appendBasicBlock('ht_unset_vk_after_str');
        $afterLong = $fn->appendBasicBlock('ht_unset_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
                $context->getTypeFromString('size_t')
            )
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterLong);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $afterObject = $fn->appendBasicBlock('ht_unset_vk_after_obj');
        $context->builder->branchIf($isObject, $illegalBlock, $afterObject);
        $context->builder->positionAtEnd($afterObject);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE, false)
        );
        $afterEnumCase = $fn->appendBasicBlock('ht_unset_vk_after_enum');
        $context->builder->branchIf($isEnumCase, $illegalBlock, $afterEnumCase);
        $context->builder->positionAtEnd($afterEnumCase);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isArray, $illegalBlock, $done);
        $context->builder->positionAtEnd($illegalBlock);
        self::emitIllegalOffsetType($context, 'Illegal offset type in unset');
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    public static function readStringAt(Context $context, Value $ht, Value $index): Value
    {
        $entry = self::listEntryPointer($context, $ht, $index);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
    }

    /**
     * Read a packed-list element into a stack {@see __value__} slot (for echo / mixed-type index).
     */
    public static function readIndexedToValueBox(Context $context, Value $ht, Value $index): Variable
    {
        return HashTableReadLlvm::readIndexedToValueBox($context, $ht, $index);
    }

    /**
     * Lvalue marker for $arr['key'] = … without reading the old value first (#107).
     */
    public static function prepareStringKeyWrite(Context $context, Value $ht, Value $keyStr): Variable
    {
        $slot = JitValueBox::alloc($context);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $var->writableHt = $ht;
        $var->writableStringKey = $keyStr;

        return $var;
    }

    /**
     * Lvalue marker for $arr[$key] = … when $key is a boxed __value__ (issue #86).
     */
    public static function prepareValueBoxKeyWrite(Context $context, Value $ht, Variable $dim): Variable
    {
        $slot = JitValueBox::alloc($context);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $var->writableHt = $ht;
        $var->writableValueBoxKey = $dim;

        return $var;
    }

    /**
     * Lvalue marker for $arr[0] = … on a native hashtable (#107).
     */
    public static function prepareIndexWrite(Context $context, Value $ht, Value $index): Variable
    {
        $slot = JitValueBox::alloc($context);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $var->writableHt = $ht;
        $var->writableIndex = $index;

        return $var;
    }

    /**
     * Writable __value__ slot for a string key (creates an empty string entry if missing; issue #103).
     */
    public static function writableStringKeyValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $create = BasicBlockHelper::append($context, 'ht_sk_write_create');
        $ready = BasicBlockHelper::append($context, 'ht_sk_write_ready');
        $context->builder->branchIf($isSet, $ready, $create);

        $context->builder->positionAtEnd($create);
        $empty = $context->builder->call($context->lookupFunction('__string__alloc'), $context->constantFromInteger(0, 'size_t'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $empty
        );
        $context->builder->branch($ready);

        $context->builder->positionAtEnd($ready);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $valPtr
        );
        $var->writableHt = $ht;
        $var->writableStringKey = $keyStr;

        return $var;
    }

    public static function unsetStringKey(Context $context, Value $ht, Value $keyStr): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            $keyStr
        );
    }

    /**
     * Read a CGI superglobal string slot without multi-block type dispatch (issue #273).
     * Avoids LLVM dominance failures on ?? left branches when the key is absent at compile time.
     */
    public static function readSuperglobalStringKeyToValueBox(
        Context $context,
        Value $ht,
        Value $keyStr
    ): Variable {
        $tag = 'sg'.(string) self::nextSeq();
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyStr
        );
        $hasValue = BasicBlockHelper::append($context, 'sg_sk_has_'.$tag);
        $missing = BasicBlockHelper::append($context, 'sg_sk_miss_'.$tag);
        $done = BasicBlockHelper::append($context, 'sg_sk_done_'.$tag);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $missing, $hasValue);

        $context->builder->positionAtEnd($missing);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hasValue);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    public static function readStringKeyToValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        return HashTableReadLlvm::readStringKeyToValueBox($context, $ht, $keyStr);
    }

    public static function initArray(Context $context, Variable $result): void
    {
        $result->nextFreeElement = 0;
        if ($result->type & Variable::IS_NATIVE_ARRAY) {
            return;
        }
        $ht = self::alloc($context);
        if (Variable::TYPE_VALUE === $result->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $result->value,
                $ht
            );
            $result->valueBoxHashtable = true;

            return;
        }
        $context->builder->store($ht, $result->value);
    }

    /**
     * Spread merge for string keys: numeric strings append; other strings overwrite (#5072).
     */
    public static function spreadAddElement(
        Context $context,
        Variable $array,
        Variable $element,
        Variable $key
    ): void {
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            if (self::nativeArrayNeedsHashtablePromotion($array, $element)) {
                self::promoteNativeArrayVariableToHashtable($context, $array);
            } else {
                self::addNativeElement($context, $array, $element, $key);

                return;
            }
        }
        $ht = self::loadHashtablePointer($context, $array);
        if (Variable::TYPE_STRING !== $key->type) {
            if (Variable::TYPE_OBJECT === $key->type || Variable::TYPE_HASHTABLE === $key->type) {
                self::emitIllegalOffsetType($context);

                return;
            }
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
            self::setAtIndex($context, $ht, $index, $element);

            return;
        }
        $keyPtr = $context->helper->loadValue($key);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($keyPtr, $map['length']));
        $charPtr = $context->builder->structGep($keyPtr, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroLen = $len->typeOf()->constInt(0, false);
        $tag = (string) self::nextSeq();
        $useStr = BasicBlockHelper::append($context, 'ht_spread_add_str_'.$tag);
        $tryInt = BasicBlockHelper::append($context, 'ht_spread_add_try_'.$tag);
        $append = BasicBlockHelper::append($context, 'ht_spread_add_append_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_spread_add_done_'.$tag);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zeroLen);
        $context->builder->branchIf($isEmpty, $useStr, $tryInt);

        $context->builder->positionAtEnd($tryInt);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'ht_spread_add_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $context->builder->branchIf($consumedAll, $append, $useStr);

        $context->builder->positionAtEnd($append);
        $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        ++$array->nextFreeElement;
        self::setAtIndex($context, $ht, $index, $element);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($useStr);
        self::setAtStringKey($context, $ht, $keyPtr, $element);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function addElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key = null
    ): void {
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            if (self::nativeArrayNeedsHashtablePromotion($array, $element)) {
                self::promoteNativeArrayVariableToHashtable($context, $array);
            } else {
                self::addNativeElement($context, $array, $element, $key);

                return;
            }
        }
        $ht = self::loadHashtablePointer($context, $array);
        $sizeT = $context->getTypeFromString('size_t');
        if (null === $key) {
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
            self::setAtIndex($context, $ht, $index, $element);

            return;
        }
        if (Variable::TYPE_OBJECT === $key->type || Variable::TYPE_HASHTABLE === $key->type) {
            self::emitIllegalOffsetType($context);

            return;
        }
        if (Variable::TYPE_NULL === $key->type) {
            $emptyKey = $context->builder->load($context->constantStringFromString(''));
            self::setAtStringKey($context, $ht, $emptyKey, $element);

            return;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $keyPtr = $context->helper->loadValue($key);
            self::setAtKeyCoercingNumericString($context, $ht, $keyPtr, $element);

            return;
        }
        if (Variable::TYPE_VALUE === $key->type || JitValueBox::isValueOperand($key)) {
            self::setValueBoxKey($context, $ht, $key, $element);

            return;
        }
        $index = self::arrayKeyToIndex($context, $key);
        self::setAtIndex($context, $ht, $index, $element);
    }

    /**
     * Native packed arrays inferred as scalar must widen when storing enum case objects (#5722, #5638).
     */
    private static function nativeArrayNeedsHashtablePromotion(Variable $array, Variable $element): bool
    {
        if (0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return false;
        }
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;

        return $element->type !== $elemType;
    }

    private static function promoteNativeArrayVariableToHashtable(Context $context, Variable $array): void
    {
        if (0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return;
        }
        $ht = self::materializeNativeArrayForCall($context, $array);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $ht
        );
        $array->type = Variable::TYPE_VALUE;
        $array->value = $slot;
        $array->valueBoxHashtable = true;
    }

    /**
     * php-src: float array keys truncate toward zero (zend_dval_to_lval).
     */
    private static function arrayKeyToIndex(Context $context, Variable $key): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (Variable::TYPE_NATIVE_DOUBLE === $key->type) {
            return $context->builder->fptosi(
                $context->helper->loadValue($key),
                $sizeT
            );
        }

        return $context->builder->truncOrBitCast(
            $context->helper->loadValue($key),
            $sizeT
        );
    }

    private static function addNativeElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key
    ): void {
        if (null !== $key) {
            $index = self::arrayKeyToIndex($context, $key);
        } else {
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
        }
        $zero = $context->constantFromInteger(0, 'size_t');
        $slot = $context->builder->inBoundsGep($array->value, $zero, $index);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        if (Variable::TYPE_STRING === $elemType) {
            $context->builder->store(self::ownedString($context, $element), $slot);
        } else {
            $context->builder->store($context->helper->loadValue($element), $slot);
        }
    }

    /**
     * Reserve the next packed-list slot for $arr[] = … (issue #116).
     *
     * Returns a {@see Variable::TYPE_VALUE} lvalue pointing at the new __value__ entry.
     */
    public static function reserveAppendSlot(Context $context, Variable $array): Variable
    {
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            $sizeT = $context->getTypeFromString('size_t');
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
            $zero = $sizeT->constInt(0, false);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $index);
            $elementType = $array->type & (~Variable::IS_NATIVE_ARRAY);

            return new Variable($context, $elementType, Variable::KIND_VARIABLE, $slot);
        }

        $ht = self::loadHashtablePointer($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        ++$array->nextFreeElement;
        $one = $sizeT->constInt(1, false);
        $need = $context->builder->addNoSignedWrap($index, $one);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);
        $entry = self::listEntryPointer($context, $ht, $index);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $entry);

        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $numElements = $context->builder->load(
            $context->builder->structGep($ht, $map['numElements'])
        );
        $updateNext = $context->builder->icmp(Builder::INT_UGE, $index, $nextFree);
        $newNext = $context->builder->select($updateNext, $need, $nextFree);
        $context->builder->store(
            $newNext,
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $updateNum = $context->builder->icmp(Builder::INT_UGE, $index, $numElements);
        $newNum = $context->builder->select($updateNum, $need, $numElements);
        $context->builder->store(
            $newNum,
            $context->builder->structGep($ht, $map['numElements'])
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $entry);
    }

    /**
     * Copy a string into an owned heap value before storing in a hashtable.
     * Temporary native-array literals and compile-time strings must not be stored by pointer alone.
     */
    private static function ownedString(Context $context, Variable $element): Value
    {
        $str = JitStringArg::stringPtrFromVariable($context, $element);

        return $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
    }

    public static function setAtIndex(Context $context, Value $ht, Value $index, Variable $element): void
    {
        HashTableWriteLlvm::setAtIndex($context, $ht, $index, $element);
    }

    /** Foreach by-ref: packed index writes vs borrowed string-key entry (#4364). */
    public static function assignForeachByRefWritable(Context $context, Variable $lvalue, Variable $element): void
    {
        if (null === $lvalue->writableHt || null === $lvalue->foreachByRefPackedArm || null === $lvalue->writableIndex) {
            throw new \LogicException('assignForeachByRefWritable requires foreach by-ref writable markers');
        }
        self::setAtIndex($context, $lvalue->writableHt, $lvalue->writableIndex, $element);
    }

    public static function setValueBoxKey(
        Context $context,
        Value $ht,
        Variable $dim,
        Variable $element
    ): void {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_set_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_set_vk_long');
        $objectBlock = $fn->appendBasicBlock('ht_set_vk_obj');
        $done = $fn->appendBasicBlock('ht_set_vk_done');
        $afterString = $fn->appendBasicBlock('ht_set_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        self::setAtStringKey($context, $ht, $keyStr, $element);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_set_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $context->getTypeFromString('size_t')
        );
        self::setAtIndex($context, $ht, $index, $element);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $done
        );
        $context->builder->positionAtEnd($objectBlock);
        $keyObj = $context->builder->call($context->lookupFunction('__value__readObject'), $valPtr);
        self::setAtObjectKey($context, $ht, $keyObj, $element);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    public static function setAtObjectKey(
        Context $context,
        Value $ht,
        Value $keyObj,
        Variable $element
    ): void {
        switch ($element->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setObjectKeyLong'),
                    $ht,
                    $keyObj,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setObjectKeyLong'),
                    $ht,
                    $keyObj,
                    $context->builder->zext(
                        $context->helper->loadValue($element),
                        $context->getTypeFromString('int64')
                    )
                );
                break;
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setObjectKeyObject'),
                    $ht,
                    $keyObj,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_VALUE:
                self::setValueBoxAtObjectKey($context, $ht, $keyObj, $element);
                break;
            default:
                throw new \LogicException(
                    'Object-key array element type not supported for JIT: '
                    .Variable::getStringType($element->type)
                );
        }
    }

    private static function setValueBoxAtObjectKey(
        Context $context,
        Value $ht,
        Value $keyObj,
        Variable $element
    ): void {
        $tag = (string) self::nextSeq();
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $element);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $longBlock = BasicBlockHelper::append($context, 'ht_ok_vb_long_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'ht_ok_vb_obj_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_ok_vb_done_'.$tag);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $objectBlock);
        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectKeyLong'),
            $ht,
            $keyObj,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($objectBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectKeyObject'),
            $ht,
            $keyObj,
            $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    /**
     * Array element write: numeric strings use the int index slot (Zend zend_hash.c; #4151).
     */
    public static function setAtKeyCoercingNumericString(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        $insert = $context->builder->getInsertBlock();
        if ($context->emitsInitLinearIR()
            || (null !== $insert && self::emitsInInitFunction($context, $insert))) {
            // __init__ must stay a linear basic-block chain; no strtol coercion CFG (#8559).
            self::setAtStringKey($context, $ht, $keyPtr, $element);

            return;
        }
        self::setAtKeyCoercingNumericStringBody($context, $ht, $keyPtr, $element);
    }

    private static function sameLlvmBasicBlock(\PHPLLVM\BasicBlock $a, \PHPLLVM\BasicBlock $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a instanceof \PHPLLVM\LLVMAbstract\BasicBlock
            && $b instanceof \PHPLLVM\LLVMAbstract\BasicBlock) {
            return $a->block === $b->block;
        }

        return false;
    }

    private static function sameLlvmFunction(?\PHPLLVM\Value $a, ?\PHPLLVM\Value $b): bool
    {
        if (null === $a || null === $b) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if ($a instanceof \PHPLLVM\LLVMAbstract\Value
            && $b instanceof \PHPLLVM\LLVMAbstract\Value) {
            return $a->value === $b->value;
        }

        return false;
    }

    private static function emitsInInitFunction(Context $context, \PHPLLVM\BasicBlock $insert): bool
    {
        if (self::sameLlvmBasicBlock($insert, $context->initBlock)) {
            return true;
        }
        $linear = $context->initLinearBlock;
        if (null !== $linear && self::sameLlvmBasicBlock($insert, $linear)) {
            return true;
        }
        $initParent = $context->initBlock->getParent();
        $insertParent = $insert->getParent();
        if (self::sameLlvmFunction($initParent, $insertParent)) {
            return true;
        }

        return false;
    }

    private static function setAtKeyCoercingNumericStringBody(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        $builder = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $len = $builder->load($builder->structGep($keyPtr, $map['length']));
        $charPtr = $builder->structGep($keyPtr, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroLen = $len->typeOf()->constInt(0, false);

        $useStr = BasicBlockHelper::append($context, 'arr_key_str_'.self::nextSeq());
        $tryInt = BasicBlockHelper::append($context, 'arr_key_try_int_'.self::nextSeq());
        $useInt = BasicBlockHelper::append($context, 'arr_key_int_'.self::nextSeq());
        $done = BasicBlockHelper::append($context, 'arr_key_done_'.self::nextSeq());

        $isEmpty = $builder->icmp(Builder::INT_EQ, $len, $zeroLen);
        $builder->branchIf($isEmpty, $useStr, $tryInt);

        $builder->positionAtEnd($tryInt);
        $endPtrSlot = $builder->alloca($i8p, 1, 'arr_key_strtol_end');
        $builder->store($i8p->constNull(), $endPtrSlot);
        $parsed = $builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $builder->load($endPtrSlot);
        $endOffset = $builder->sub(
            $builder->ptrToInt($endPtr, $i64),
            $builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $builder->branchIf($consumedAll, $useInt, $useStr);

        $builder->positionAtEnd($useInt);
        $index = $builder->truncOrBitCast($parsed, $sizeT);
        self::setAtIndex($context, $ht, $index, $element);
        $builder->branch($done);

        $builder->positionAtEnd($useStr);
        self::setAtStringKey($context, $ht, $keyPtr, $element);
        $builder->branch($done);

        $builder->positionAtEnd($done);
    }

    public static function setAtStringKey(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        HashTableWriteLlvm::setAtStringKey($context, $ht, $keyPtr, $element);
    }

    /**
     * SplObjectStorage-style map: object identity keys (issue #600 / self-host Compiler.php).
     */
    public static function readObjectKeyToValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        return HashTableReadLlvm::readObjectKeyToValueBox($context, $ht, $keyObj);
    }

    public static function writableObjectKeyValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetObjectKey'),
            $ht,
            $keyObj
        );
        $create = BasicBlockHelper::append($context, 'ht_ok_write_create');
        $ready = BasicBlockHelper::append($context, 'ht_ok_write_ready');
        $context->builder->branchIf($isSet, $ready, $create);

        $context->builder->positionAtEnd($create);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectKeyLong'),
            $ht,
            $keyObj,
            $context->constantFromInteger(0, 'int64')
        );
        $context->builder->branch($ready);

        $context->builder->positionAtEnd($ready);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readObjectKeyValue'),
            $ht,
            $keyObj
        );
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $valPtr
        );
        $var->writableHt = $ht;
        $var->writableObjectKey = $keyObj;

        return $var;
    }

    /**
     * Copy a compile-time native array into a refcounted __hashtable__ for calls/properties (issue #767).
     */
    public static function materializeNativeArrayForCall(Context $context, Variable $array): Value
    {
        if (0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('materializeNativeArrayForCall requires a native array');
        }
        $dest = self::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'native_ht_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'native_ht_head');
        $body = BasicBlockHelper::append($context, 'native_ht_body');
        $advance = BasicBlockHelper::append($context, 'native_ht_advance');
        $done = BasicBlockHelper::append($context, 'native_ht_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        if (Variable::TYPE_STRING === $elemType) {
            $elem = new Variable($context, $elemType, Variable::KIND_VARIABLE, $slot);
        } else {
            $elem = new Variable(
                $context,
                $elemType,
                Variable::KIND_VALUE,
                $context->builder->load($slot)
            );
        }
        self::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->store($count, $context->builder->structGep($dest, $map['numElements']));
        $context->builder->store($count, $context->builder->structGep($dest, $map['nextFreeElement']));

        $context->refcount->addref($dest);

        return $dest;
    }

    /**
     * Normalize a call/unpack operand to a packed __hashtable__* (issue #1361).
     */
    public static function coerceToPackedHashtable(Context $context, Variable $source): Variable
    {
        if ($source->type & Variable::IS_NATIVE_ARRAY) {
            return new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                self::materializeNativeArrayForCall($context, $source)
            );
        }
        if (Variable::TYPE_HASHTABLE === $source->type) {
            return $source;
        }
        if (Variable::TYPE_VALUE === $source->type || $source->valueBoxHashtable) {
            $ht = self::ensureHashtablePointer($context, $source);

            return new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $ht
            );
        }
        if (Variable::TYPE_OBJECT === $source->type) {
            $ptr = $context->helper->loadValue($source);

            return new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $context->builder->pointerCast(
                    $ptr,
                    $context->getTypeFromString('__hashtable__*')
                )
            );
        }

        throw new \LogicException(
            'Array spread/unpack requires an array, got '.Variable::getStringType($source->type)
        );
    }

    /**
     * Array-literal spread: append packed list then string keys (issue #141, #1361, #4453).
     */
    public static function spreadInto(Context $context, Variable $dest, Variable $source): void
    {
        if (self::needsTraversableMaterialization($context, $source)) {
            $srcPtr = \PHPCompiler\ext\standard\JitIteratorToArray::materializeHashtable(
                $context,
                $source,
                true,
                $source->userType ?? null
            );
            self::spreadPackedInto($context, $dest, $srcPtr);
            self::spreadStringKeysInto($context, $dest, $srcPtr);

            return;
        }
        $srcHt = self::coerceToPackedHashtable($context, $source);
        $srcPtr = $context->helper->loadValue($srcHt);
        self::spreadPackedInto($context, $dest, $srcPtr);
        self::spreadStringKeysInto($context, $dest, $srcPtr);
    }

    private static function needsTraversableMaterialization(Context $context, Variable $source): bool
    {
        if (ListUnpackHelper::isDefinitelyArrayAtCompileTime($source)) {
            return false;
        }
        if (GeneratorHelper::isGeneratorVariable($source)) {
            return true;
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $source, $source->userType ?? null)) {
            return true;
        }

        return false;
    }

    /**
     * Merge ARG_SEND entries that may include unpack markers (issue #1361).
     *
     * @param list<Variable|array{unpack: Variable}> $entries
     */
    public static function mergeCallArgEntries(Context $context, array $entries): Variable
    {
        CallUnpackRuntime::ensureLinked($context);

        if (1 === \count($entries)) {
            $only = $entries[0];
            if (\is_array($only) && isset($only['unpack'])) {
                ListUnpackHelper::emitCallUnpackOperandCheck($context, $only['unpack']);
                ListUnpackHelper::emitCheck($context, $only['unpack']);

                return self::coerceToPackedHashtable($context, $only['unpack']);
            }
        }

        $dest = self::emptyVariable($context);
        $destVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $dest->value
        );
        foreach ($entries as $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                ListUnpackHelper::emitCallUnpackOperandCheck($context, $entry['unpack']);
                ListUnpackHelper::emitCheck($context, $entry['unpack']);
                self::spreadInto($context, $destVar, $entry['unpack']);
                continue;
            }
            if (\is_array($entry) && isset($entry['named'])) {
                $nameVar = new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $context->constantFromString((string) $entry['named'])
                );
                self::addElement($context, $destVar, $entry['value'], $nameVar);
                continue;
            }
            $value = \is_array($entry) ? ($entry['v'] ?? $entry['value'] ?? null) : $entry;
            self::addElement($context, $destVar, $value, null);
        }

        return $destVar;
    }

    private static function spreadPackedInto(Context $context, Variable $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($srcHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_spread_packed_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_spread_packed_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_spread_packed_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_spread_packed_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $skip = BasicBlockHelper::append($context, 'ht_spread_packed_skip_'.$tag);
        $append = BasicBlockHelper::append($context, 'ht_spread_packed_append_'.$tag);
        $context->builder->branchIf($isSet, $append, $skip);

        $context->builder->positionAtEnd($append);
        $elem = self::readIndexedToValueBox($context, $srcHt, $idx);
        self::addElement($context, $dest, $elem, null);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function spreadStringKeysInto(Context $context, Variable $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_spread_str_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_spread_str_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_spread_str_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_spread_str_done_'.$tag);
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($srcHt, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $keyStr
        );
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        self::spreadAddElement($context, $dest, $elem, $keyVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    public static function emitIllegalOffsetType(Context $context, string $message = 'Illegal offset type'): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
    }
}
