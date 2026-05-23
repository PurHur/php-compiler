<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\Block;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\VM\Variable as VMVariable;

use PHPLLVM;

final class Variable {
    const TYPE_NULL = 0;
    const TYPE_NATIVE_LONG = 1;
    const TYPE_NATIVE_BOOL = 2;
    const TYPE_NATIVE_DOUBLE = 3;
    const TYPE_STRING = 4 | self::IS_REFCOUNTED;
    const TYPE_OBJECT = 5 | self::IS_REFCOUNTED;
    const TYPE_VALUE = 6 | self::IS_REFCOUNTED;
    const TYPE_HASHTABLE = 7 | self::IS_REFCOUNTED;

    const TYPE_MAP = [
        Type::TYPE_DOUBLE => self::TYPE_NATIVE_DOUBLE,
        Type::TYPE_LONG => self::TYPE_NATIVE_LONG,
        Type::TYPE_BOOLEAN => self::TYPE_NATIVE_BOOL,
        Type::TYPE_STRING => self::TYPE_STRING,
        Type::TYPE_OBJECT => self::TYPE_OBJECT,
        Type::TYPE_ARRAY => self::TYPE_HASHTABLE,
    ];

    const NATIVE_TYPE_MAP = [
        self::TYPE_NATIVE_LONG => 'int64',
        self::TYPE_NATIVE_BOOL => 'int1',
        self::TYPE_NATIVE_DOUBLE => 'double',
        self::TYPE_STRING => '__string__*',
        self::TYPE_OBJECT => '__object__*',
        self::TYPE_VALUE => '__value__',
        self::TYPE_HASHTABLE => '__hashtable__*',
    ];

    const IS_NATIVE_ARRAY = 1 << 6;
    const IS_REFCOUNTED   = 1 << 7;

    public int $type;

    const KIND_VARIABLE = 1;
    const KIND_VALUE = 2;
    public int $kind;

    public PHPLLVM\Value $value;
    private Context $context;

    /** When this hashtable is a compile-time superglobal (e.g. $_GET). */
    public ?string $superglobalName = null;

    /**
     * When set, string assignment updates this hashtable entry in place (issue #103).
     *
     * @var \PHPLLVM\Value|null
     */
    public ?\PHPLLVM\Value $writableHt = null;

    /** @var \PHPLLVM\Value|null */
    public ?\PHPLLVM\Value $writableStringKey = null;

    /** @var \PHPLLVM\Value|null */
    public ?\PHPLLVM\Value $writableObjectKey = null;

    /** @var \PHPLLVM\Value|null */
    public ?\PHPLLVM\Value $writableIndex = null;

    /** String literal value when this variable represents a constant string operand. */
    public ?string $compileTimeString = null;

    /** Set when this variable is the PHP {@code null} constant (const-fetch). */
    public bool $isNullConstant = false;

    /** {@see __value__} slot holds a nested {@see __hashtable__} (e.g. $_FILES['field']). */
    public bool $valueBoxHashtable = false;

    /** Hashtable pointer from {@see __value__readHashtable}; do not addref/delref (issue #107). */
    public bool $borrowedHashtable = false;

    /** void** property slot on {@see __object__} when this variable is a property lvalue (#58). */
    public ?\PHPLLVM\Value $objectPropertySlot = null;

    /** Callee slot for a literal-include caller local; skip delref in unrelated assigns (#866). */
    public bool $includeBinding = false;

    /** Declared JIT property type when {@see $objectPropertySlot} is set. */
    public ?int $objectPropertyType = null;

    private static int $lvalueCounter = 0;
    public int $nextFreeElement = 0;

    public function __construct(
        Context $context, 
        int $type, 
        int $kind, 
        PHPLLVM\Value $value
    ) {
        $this->context = $context;
        $this->type = $type;
        $this->kind = $kind;
        $this->value = $value;
    }

    public static function fromVMVariable(int $type): int {
        switch ($type) {
            case VMVariable::TYPE_INTEGER: return self::TYPE_NATIVE_LONG;
            case VMVariable::TYPE_FLOAT: return self::TYPE_NATIVE_DOUBLE;
            case VMVariable::TYPE_BOOLEAN: return self::TYPE_NATIVE_BOOL;
            case VMVariable::TYPE_STRING: return self::TYPE_STRING;
        }
        throw new \LogicException("Not implemented type conversion: $type");
    }

    /**
     * Map VM {@see VMVariable} type tags to the int8 stored in __value__::type.
     */
    public static function jitTypeByteFromVmType(int $vmType): int
    {
        switch ($vmType) {
            case VMVariable::TYPE_NULL:
                return self::TYPE_NULL;
            case VMVariable::TYPE_INTEGER:
                return self::TYPE_NATIVE_LONG;
            case VMVariable::TYPE_FLOAT:
                return self::TYPE_NATIVE_DOUBLE;
            case VMVariable::TYPE_BOOLEAN:
                return self::TYPE_NATIVE_BOOL;
            case VMVariable::TYPE_STRING:
                return self::TYPE_STRING;
            case VMVariable::TYPE_OBJECT:
                return self::TYPE_OBJECT;
            case VMVariable::TYPE_ARRAY:
                return self::TYPE_HASHTABLE;
            default:
                throw new \LogicException('Unknown VM type for JIT type byte: '.$vmType);
        }
    }

    public static function getStringTypeFromType(Type $type): string {
        return self::getStringType(self::getTypeFromType($type));
    }

    public static function getStringType(int $type): string {
        if ($type & self::IS_NATIVE_ARRAY) {
            $base = $type & ~self::IS_NATIVE_ARRAY;
            if (isset(self::NATIVE_TYPE_MAP[$base])) {
                return self::NATIVE_TYPE_MAP[$base].'[]';
            }
        }
        if (isset(self::NATIVE_TYPE_MAP[$type])) {
            return self::NATIVE_TYPE_MAP[$type];
        }
        if (self::TYPE_NULL === $type || self::TYPE_VALUE === $type) {
            return '__value__';
        }

        return 'unknown(type='.$type.')';
    }

    public static function getTypeFromType(?Type $type): int {
        if (null === $type) {
            return self::TYPE_VALUE;
        }
        if (Type::TYPE_UNION === $type->type && [] !== ($type->subTypes ?? [])) {
            $nonNull = [];
            $hasNull = false;
            foreach ($type->subTypes as $sub) {
                if (Type::TYPE_NULL === $sub->type) {
                    $hasNull = true;
                } else {
                    $nonNull[] = $sub;
                }
            }
            if ($hasNull) {
                return self::TYPE_VALUE;
            }
            if (1 === count($nonNull)) {
                return self::getTypeFromType($nonNull[0]);
            }
        }
        if (null !== $type->userType && 0 === strcasecmp($type->userType, 'SplObjectStorage')) {
            return self::TYPE_HASHTABLE;
        }
        if (isset(self::TYPE_MAP[$type->type])) {
            return self::TYPE_MAP[$type->type];
        }
        if ($type->type === Type::TYPE_OBJECT) {
            return self::TYPE_OBJECT;
        }
        if ($type->type === Type::TYPE_ARRAY) {
            return self::TYPE_HASHTABLE;
        }
        if ($type->type === Type::TYPE_NULL) {
            return self::TYPE_NULL;
        }
        return self::TYPE_VALUE;
    }

    /**
     * Returns a writable variable (lvalue)
     */
    public static function fromOp(
        Context $context,
        PHPLLVM\Value\Function_ $func,
        PHPLLVM\BasicBlock $basicBlock,
        Block $block,
        Operand $op
    ): Variable {
        $type = self::getTypeFromType($op->type);
        if ($type === self::TYPE_NULL) {
            $slot = JitValueBox::alloc($context);

            return new Variable(
                $context,
                self::TYPE_VALUE,
                self::KIND_VARIABLE,
                $slot
            );
        }
        $stringType = self::getStringType($type);
        if ($type === self::TYPE_HASHTABLE) {
            // see if it can be converted into a native array
            if (!$context->analyzer->canEscape($op)) {
                $size = $context->analyzer->computeStaticArraySize($op);
                if (!is_null($size) && !$context->analyzer->hasDynamicArrayAppend($op, $size)) {
                    $subTypes = $op->type->subTypes ?? [];
                    if ([] !== $subTypes) {
                        $origType = self::getTypeFromType($subTypes[0]);
                        $type = self::IS_NATIVE_ARRAY | $origType;
                        $stringType = self::getStringType($origType) . '[' . $size . ']';
                    }
                }
            }
        }
        return new Variable(
            $context,
            $type,
            self::KIND_VARIABLE,
            BasicBlockHelper::entryAlloca($context, $context->getTypeFromString($stringType))
        );
    }

    /**
     * Returns a readable variable (rvalue)
     */
    public static function fromValueOp(
        Context $context,
        PHPLLVM\Value $value,
        Operand $op
    ): Variable {
        $llvmType = $context->getStringFromType($value->typeOf());
        if ('__value__*' === $llvmType || '__value__' === $llvmType) {
            $type = self::TYPE_VALUE;
        } elseif ('__hashtable__*' === $llvmType) {
            $type = self::TYPE_HASHTABLE;
        } elseif ('__string__*' === $llvmType) {
            $type = self::TYPE_STRING;
        } elseif ('__object__*' === $llvmType) {
            $type = self::TYPE_OBJECT;
        } else {
            $type = self::getTypeFromType($op->type);
        }
        return new Variable(
            $context,
            $type,
            self::KIND_VALUE,
            $value
        );
    }

    public static function fromLiteral(Context $context, Operand $op): Variable {
        $type = self::getTypeFromType($op->type);
        switch ($type) {
            case self::TYPE_NATIVE_LONG:
                $value = $context->constantFromInteger($op->value, self::getStringType($type));
                break;
            case self::TYPE_STRING:
                $value = $context->builder->load($context->constantStringFromString($op->value));
                $literal = is_string($op->value) ? $op->value : null;
                break;
            case self::TYPE_NATIVE_DOUBLE:
                $value = $context->constantFromFloat($op->value, self::getStringType($type));
                break;
            case self::TYPE_NATIVE_BOOL:
                $value = $context->constantFromBool($op->value);
                break;
            case self::TYPE_NULL:
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                return new Variable(
                    $context,
                    self::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
            default:
                throw new \LogicException("Literal type " . self::getStringType($type) . " not yet supported");
        }
        $var = new Variable(
            $context,
            $type,
            self::KIND_VALUE,
            $value
        );
        if (isset($literal)) {
            $var->compileTimeString = $literal;
        }

        return $var;
    }

    public static function fromConstantInt(Context $context, int $value): Variable {
        return new Variable(
            $context,
            self::TYPE_NATIVE_LONG,
            self::KIND_VALUE,
            $context->constantFromInteger($value)
        );
    }

    public function castTo(int $type): self {
        switch ($type) {
            case self::TYPE_NATIVE_LONG:
                switch ($this->type) {
                    case self::TYPE_NATIVE_LONG:
                        return $this;
                    case self::TYPE_NATIVE_DOUBLE:
                        return new self(
                            $this->context,
                            $type,
                            self::KIND_VALUE,
                            $this->context->builder->fpToSi(
                                $this->value,
                                $this->context->getTypeFromString('long long')
                            )
                        );
                    case self::TYPE_STRING:
                        if (null !== $this->compileTimeString) {
                            return new self(
                                $this->context,
                                $type,
                                self::KIND_VALUE,
                                $this->context->constantFromInteger((int) $this->compileTimeString)
                            );
                        }
                        break;
                    case self::TYPE_NATIVE_BOOL:
                        return new self(
                            $this->context, 
                            $type,
                            self::KIND_VALUE,
                            $this->context->builder->trunc($this->value, $this->context->getTypeFromString('bool'))
                        );
                }
                break;
            case self::TYPE_NATIVE_BOOL:
                switch ($this->type) {
                    case self::TYPE_NATIVE_BOOL:
                        return $this;
                    case self::TYPE_NATIVE_DOUBLE:
                        return new self(
                            $this->context, 
                            $type,
                            self::KIND_VALUE,
                            $this->context->builder->siToFp($this->value, $this->context->getTypeFromString('double'))
                        );
                    case self::TYPE_NATIVE_LONG:
                        return new self(
                            $this->context, 
                            $type,
                            self::KIND_VALUE,
                            $this->context->builder->zEdt($this->value, $this->context->getTypeFromString('long long'))
                        );
                    case self::TYPE_VALUE:
                        return new self(
                            $this->context,
                            $type,
                            self::KIND_VALUE,
                            (new \PHPCompiler\ext\standard\boolval())->call($this->context, $this)
                        );
                }
                break;
            case self::TYPE_NATIVE_DOUBLE:
                switch ($this->type) {
                    case self::TYPE_NATIVE_DOUBLE:
                        return $this;
                    case self::TYPE_NATIVE_LONG:
                        return new self(
                            $this->context,
                            $type,
                            self::KIND_VALUE,
                            $this->context->builder->siToFp(
                                $this->value,
                                $this->context->getTypeFromString('double')
                            )
                        );
                    case self::TYPE_NATIVE_BOOL:
                        return new self(
                            $this->context, 
                            $type,
                            self::KIND_VALUE,
                            $this->context->builder->fpToSi($this->value, $this->context->getTypeFromString('bool'))
                        );
                }
                break;
        }
        throw new \LogicException('Unhandlable cast operation to type: ' . $type);
    }

    public function addref(): void {
        if ($this->type & self::IS_NATIVE_ARRAY) {
            $elemType = $this->type & ~self::IS_NATIVE_ARRAY;
            if (0 === ($elemType & self::IS_REFCOUNTED)) {
                return;
            }
            for ($i = 0; $i < $this->nextFreeElement; $i++) {
                $this->dimFetch(self::fromConstantInt($this->context, $i))->addref();
            }

            return;
        }
        if (!($this->type & self::IS_REFCOUNTED) || self::TYPE_VALUE === $this->type) {
            return;
        }
        if (null !== $this->objectPropertySlot) {
            return;
        }
        $ptr = self::KIND_VALUE === $this->kind
            ? $this->value
            : $this->context->helper->loadValue($this);
        $this->context->refcount->addref($ptr);
    }

    public function free(): void {
        if ($this->includeBinding) {
            return;
        }
        if ($this->kind === self::KIND_VALUE) {
            return;
        }
        switch ($this->type) {
            case self::TYPE_NATIVE_LONG:
            case self::TYPE_NATIVE_BOOL:
            case self::TYPE_NATIVE_DOUBLE:
                return;
        }
        if ($this->type === self::TYPE_VALUE || $this->type === self::TYPE_NULL) {
            // TODO: free owned resources
            return;
        }
        if ($this->type & self::IS_NATIVE_ARRAY) {
            // free each
            for ($i = 0; $i < $this->nextFreeElement; $i++) {
                $this->dimFetch(self::fromConstantInt($this->context, $i))->free();
            }
            return;
        }
        if ($this->type & self::IS_REFCOUNTED) {
            if (null !== $this->objectPropertySlot) {
                return;
            }
            $ptr = self::KIND_VALUE === $this->kind
                ? $this->value
                : $this->context->helper->loadValue($this);
            $this->context->refcount->delref($ptr);

            return;
        }
        throw new \LogicException('Unknown free type: ' . $this->type);
    }

    public function initialize(): void {
        if ($this->kind === self::KIND_VALUE) {
            return;
        }
        switch ($this->type) {
            case self::TYPE_STRING:
                // assign to null
                $this->context->builder->store($this->context->type->string->pointer->constNull(), $this->value);
                break;
            case self::TYPE_OBJECT:
                $this->context->builder->store(
                    $this->context->type->object->pointer->constNull(),
                    $this->value
                );
                break;
            case self::TYPE_HASHTABLE:
                $this->context->builder->store(
                    $this->context->getTypeFromString('__hashtable__*')->constNull(),
                    $this->value
                );
                break;
            case self::TYPE_VALUE:
            case self::TYPE_NULL:
                $map = $this->context->structFieldMap['__value__'];
                $this->context->builder->store(
                    $this->context->getTypeFromString('int8')->constInt(self::TYPE_NULL, false),
                    $this->context->builder->structGep($this->value, $map['type'])
                );
                break;
        }
    }
    
    public function toString(\gcc_jit_block_ptr $block): Variable {
        switch ($this->type) {
            case self::TYPE_STRING:
                return $this;
        }
    }

    public function dimFetch(self $dim, ?Type $expectedType = null, bool $forWrite = false): Variable {
        switch ($this->type) {
            case self::TYPE_STRING:
                $charPtr = StringOffsetHelper::dimFetch(
                    $this->context,
                    $this->value,
                    $dim
                );
                if ($forWrite) {
                    return new Variable(
                        $this->context,
                        self::TYPE_STRING,
                        self::KIND_VALUE,
                        $charPtr,
                    );
                }
                $str = StringOffsetHelper::readAsString($this->context, $charPtr);

                return new Variable(
                    $this->context,
                    self::TYPE_STRING,
                    self::KIND_VALUE,
                    $str,
                );
            case self::TYPE_HASHTABLE:
                // Property slots own the hashtable; transient delref would free it (#58).
                $propertyBacked = null !== $this->objectPropertySlot;
                $container = HashTableHelper::asDetachedHashtable($this->context, $this);
                if (
                    !$forWrite
                    && null !== $container->superglobalName
                    && '_FILES' !== $container->superglobalName
                    && self::TYPE_STRING === $dim->type
                    && (null === $expectedType || Type::TYPE_ARRAY !== $expectedType->type)
                ) {
                    $key = $dim->compileTimeString;
                    if (null !== $key) {
                        $baked = Builtin::LOAD_TYPE_EMBED === $this->context->loadType
                            ? null
                            : SuperglobalInit::compileTimeReadString(
                                $this->context,
                                $container->superglobalName,
                                $key
                            );
                        if (null !== $baked) {
                            return new Variable(
                                $this->context,
                                self::TYPE_STRING,
                                self::KIND_VALUE,
                                $baked
                            );
                        }
                        $ht = $this->context->helper->loadValue($container);
                        $keyVal = $this->context->helper->loadValue($dim);

                        return HashTableHelper::readSuperglobalStringKeyToValueBox(
                            $this->context,
                            $ht,
                            $keyVal
                        );
                    }
                }
                $ht = $this->context->helper->loadValue($container);
                if (self::TYPE_VALUE === $dim->type || self::TYPE_OBJECT === $dim->type) {
                    $keyObj = self::TYPE_OBJECT === $dim->type
                        ? $this->context->helper->loadValue($dim)
                        : $this->context->builder->call(
                            $this->context->lookupFunction('__value__readObject'),
                            self::KIND_VARIABLE === $dim->kind
                                ? JitValueBox::pointer($this->context, $dim->value)
                                : $this->context->helper->loadValue($dim)
                        );
                    if ($forWrite) {
                        return HashTableHelper::writableObjectKeyValueBox($this->context, $ht, $keyObj);
                    }

                    return HashTableHelper::readObjectKeyToValueBox($this->context, $ht, $keyObj);
                }
                if (self::TYPE_STRING === $dim->type) {
                    $key = $this->context->helper->loadValue($dim);
                    if ($forWrite && (null === $expectedType || Type::TYPE_ARRAY !== $expectedType->type)) {
                        return HashTableHelper::prepareStringKeyWrite($this->context, $ht, $key);
                    }
                    if ('_FILES' === $container->superglobalName && !$forWrite) {
                        $childHt = $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__readStringKeyHashtable'),
                            $ht,
                            $key
                        );
                        return new Variable(
                            $this->context,
                            self::TYPE_HASHTABLE,
                            self::KIND_VALUE,
                            $childHt
                        );
                    }
                    if (null !== $expectedType && Type::TYPE_ARRAY === $expectedType->type) {
                        $childHt = $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__readStringKeyHashtable'),
                            $ht,
                            $key
                        );

                        return new Variable(
                            $this->context,
                            self::TYPE_HASHTABLE,
                            self::KIND_VALUE,
                            $childHt
                        );
                    }
                    if (null !== $expectedType && Type::TYPE_STRING === $expectedType->type) {
                        if (!$propertyBacked && !$this->borrowedHashtable) {
                            $this->context->refcount->addref($ht);
                        }
                        $boxed = HashTableHelper::readStringKeyToValueBox($this->context, $ht, $key);

                        return $boxed;
                    }

                    if (!$propertyBacked && !$this->borrowedHashtable) {
                        $this->context->refcount->addref($ht);
                    }
                    $boxed = HashTableHelper::readStringKeyToValueBox($this->context, $ht, $key);

                    return $boxed;
                }
                $index = self::materializePackedIndex($this->context, $dim);
                if ($forWrite) {
                    return HashTableHelper::prepareIndexWrite($this->context, $ht, $index);
                }
                if (null !== $expectedType && Type::TYPE_STRING === $expectedType->type) {
                    if (!$propertyBacked && !$this->borrowedHashtable) {
                        $this->context->refcount->addref($ht);
                    }
                    $str = $this->context->builder->call(
                        $this->context->lookupFunction('__hashtable__readStringAt'),
                        $ht,
                        $index
                    );
                    $owned = $this->context->builder->call(
                        $this->context->lookupFunction('__string__separate'),
                        $str
                    );
                    if (!$propertyBacked && !$this->borrowedHashtable && null === $container->superglobalName) {
                        $this->context->refcount->delref($ht);
                    }

                    return new Variable(
                        $this->context,
                        self::TYPE_STRING,
                        self::KIND_VALUE,
                        $owned
                    );
                }
                if (!$propertyBacked && !$this->borrowedHashtable && null === $container->superglobalName) {
                    $this->context->refcount->addref($ht);
                }
                $boxed = HashTableHelper::readIndexedToValueBox($this->context, $ht, $index);
                if (!$propertyBacked && !$this->borrowedHashtable && null === $container->superglobalName) {
                    $this->context->refcount->delref($ht);
                }

                return $boxed;
            case self::TYPE_VALUE:
                $childHt = HashTableHelper::loadHashtablePointer($this->context, $this);
                $htVar = new Variable(
                    $this->context,
                    self::TYPE_HASHTABLE,
                    self::KIND_VALUE,
                    $childHt
                );
                $htVar->borrowedHashtable = true;

                return $htVar->dimFetch($dim, $expectedType, $forWrite);
            default:
                if (!($this->type & self::IS_NATIVE_ARRAY)) {
                    throw new \LogicException("Unsupported dim fetch on " . self::getStringType($this->type));
                }
                $offset = $dim->castTo(self::TYPE_NATIVE_LONG);
                $sizeT = $this->context->getTypeFromString('size_t');
                $zero = $this->context->constantFromInteger(0, 'size_t');
                $index = $this->context->builder->truncOrBitCast(
                    $this->context->helper->loadValue($offset),
                    $sizeT
                );
                $slot = $this->context->builder->inBoundsGep($this->value, $zero, $index);

                return new Variable(
                    $this->context,
                    $this->type & (~self::IS_NATIVE_ARRAY),
                    self::KIND_VARIABLE,
                    $slot
                );
        }
    }

    /**
     * Load a packed-list index as size_t. Literal long dims are stored to a stack slot
     * first so later LLVM blocks still see a stable index (#AOT array_fill reads).
     */
    private static function materializePackedIndex(Context $context, self $dim): \PHPLLVM\Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $indexVal = $context->builder->truncOrBitCast(
            $context->helper->loadValue($dim),
            $sizeT
        );
        if (self::TYPE_NATIVE_LONG !== $dim->type || self::KIND_VALUE !== $dim->kind) {
            return $indexVal;
        }
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($indexVal, $idxSlot);

        return $context->builder->load($idxSlot);
    }
}

// Precomputed (left << 16) | right — file-level expr breaks AOT global const (#851).
const TYPE_PAIR_NATIVE_LONG_NATIVE_LONG = 65537;
const TYPE_PAIR_NATIVE_DOUBLE_NATIVE_DOUBLE = 196611;
const TYPE_PAIR_NATIVE_LONG_NATIVE_DOUBLE = 65539;
const TYPE_PAIR_NATIVE_DOUBLE_NATIVE_LONG = 196609;
const TYPE_PAIR_NATIVE_LONG_NATIVE_BOOL = 65538;
const TYPE_PAIR_NATIVE_BOOL_NATIVE_LONG = 131073;
const TYPE_PAIR_NATIVE_BOOL_NATIVE_BOOL = 131074;
const TYPE_PAIR_STRING_STRING = 8650884;

function type_pair(int $left, int $right): int {
    return ($left << 16) | $right;
}
