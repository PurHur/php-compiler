<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPTypes\Type;
use PHPCompiler\GenericArrayTypeSpec;
use PHPCompiler\OpCode;
use PHPCompiler\ext\standard\VmString;

final class Variable {
    const TYPE_UNDEFINED = -1;
    const TYPE_NULL = 0;
    const TYPE_INTEGER = 1;
    const TYPE_FLOAT = 2;
    const TYPE_BOOLEAN = 3;
    const TYPE_STRING = 4;
    const TYPE_OBJECT = 5;
    const TYPE_ARRAY = 6;
    const TYPE_INDIRECT = 7;
    /** Writable single-byte view of a parent string (Zend-style $str[$i]). */
    const TYPE_STRING_OFFSET = 8;
    /** Zend enum case object for E::Case fetches (#3420, #3554). */
    const TYPE_ENUM_CASE = 9;


    const NUMERIC = self::TYPE_INTEGER | self::TYPE_FLOAT;

    /** castFrom() target: promote to int or float (distinct from TYPE_BOOLEAN, which shares value 3). */
    private const CAST_NUMERIC = 64;

    public int $type = self::TYPE_NULL;

    private string $string;
    private int $integer;
    private float $float;
    private bool $bool;
    private ObjectEntry $object;
    private EnumCaseEntry $enumCase;
    private Variable $indirect;
    private HashTable $array;
    private Variable $stringOffsetParent;
    private int $stringOffsetIndex;
    private ?ErrorReporter $stringOffsetReporter = null;
    private ?string $stringOffsetFile = null;


    public int $next = -1;

    public ?int $typeConstraint = null;
    public ?string $classConstraint = null;

    /** list&lt;T&gt; / array&lt;K,V&gt; shape when declaration used generic array syntax (#3705). */
    public ?GenericArrayTypeSpec $genericArrayTypeSpec = null;

    /** Set for instance properties so readonly-class writes can be enforced (issue #1360). */
    public ?ObjectEntry $objectPropertyOwner = null;

    public ?string $objectPropertyName = null;

    /** Stream handle from fopen()/similar; distinguishes handle ints from plain integers (#3519). */
    public bool $streamResource = false;

    public function __construct(int $type = self::TYPE_NULL) {
        $this->type = $type;
    }

    public static function mapFromType(?Type $type): int {
        if (null === $type) {
            return self::TYPE_UNDEFINED;
        }
        switch ($type->type) {
            case Type::TYPE_NULL:
                return self::TYPE_NULL;
            case Type::TYPE_LONG:
                return self::TYPE_INTEGER;
            case Type::TYPE_DOUBLE:
                return self::TYPE_FLOAT;
            case Type::TYPE_BOOLEAN:
                return self::TYPE_BOOLEAN;
            case Type::TYPE_OBJECT:
                return self::TYPE_OBJECT;
            case Type::TYPE_STRING:
                return self::TYPE_STRING;
            case Type::TYPE_ARRAY:
                return self::TYPE_ARRAY;
        }
        return self::TYPE_UNDEFINED;
    }

    public function isUndefined(): bool {
        return $this->type === self::TYPE_UNDEFINED;
    }

    public function resolveIndirect(): self {
        $var = $this;
        while ($var->type === self::TYPE_INDIRECT) {
            $var = $var->indirect;
        }
        return $var;
    }

    public function isIndirect(): bool
    {
        return self::TYPE_INDIRECT === $this->type;
    }

    public function newArray(): HashTable {
        $this->array(new HashTable);
        return $this->array;
    }

    public function array(HashTable $ht): void {
        $this->reset();
        $this->type = self::TYPE_ARRAY;
        $this->array = $ht;
    }

    public function toArray(): HashTable {
        switch ($this->type) {
            case self::TYPE_NULL:
                return new HashTable;
            case self::TYPE_ARRAY:
                return $this->array;
        }
        $ht = new HashTable;
        $ht->append($this);
        return $ht;
    }

    public function int(int $value): void {
        $this->reset();
        $this->type = self::TYPE_INTEGER;
        $this->integer = $value;
        $this->streamResource = false;
    }

    public function streamHandle(int $value): void
    {
        $this->int($value);
        $this->streamResource = true;
    }

    public function isStreamResource(): bool
    {
        return $this->streamResource && self::TYPE_INTEGER === $this->type;
    }

    public function is(int $type): bool {
        if ($this->type === $type) {
            return true;
        }
        if ($this->type === self::TYPE_INDIRECT) {
            return $this->indirect->is($type);
        }
        return false;
    }

    public function toInt(?\PHPCompiler\VM $vm = null): int {
        if (self::TYPE_INDIRECT === $this->type) {
            return $this->indirect->toInt($vm);
        }
        TypedPropertyCheck::assertReadable($this);
        switch ($this->type) {
            case self::TYPE_NULL:
                return 0;
            case self::TYPE_INTEGER:
                return $this->integer;
            case self::TYPE_FLOAT:
                return (int) $this->float;
            case self::TYPE_BOOLEAN:
                return $this->bool ? 1 : 0;
            case self::TYPE_STRING:
                return (int) $this->string;
            case self::TYPE_OBJECT:
                return $this->objectToScalarString($vm, 'int')->toInt($vm);
        }
        throw new \LogicException("Cannot convert type {$this->type} to int");
    }

    public function float(float $value): void {
        $this->reset();
        $this->type = self::TYPE_FLOAT;
        $this->float = $value;
    }

    public function toFloat(?\PHPCompiler\VM $vm = null): float {
        if (self::TYPE_INDIRECT === $this->type) {
            return $this->indirect->toFloat($vm);
        }
        TypedPropertyCheck::assertReadable($this);
        switch ($this->type) {
            case self::TYPE_NULL:
                return 0.0;
            case self::TYPE_INTEGER:
                return (float) $this->integer;
            case self::TYPE_FLOAT:
                return $this->float;
            case self::TYPE_BOOLEAN:
                return $this->bool ? 1.0 : 0.0;
            case self::TYPE_STRING:
                return (float) $this->string;
            case self::TYPE_OBJECT:
                return $this->objectToScalarString($vm, 'float')->toFloat($vm);
        }
        throw new \LogicException("Cannot convert type {$this->type} to float");
    }

    public function toNumeric(?\PHPCompiler\VM $vm = null) {
        if (self::TYPE_INDIRECT === $this->type) {
            return $this->indirect->toNumeric($vm);
        }
        TypedPropertyCheck::assertReadable($this);
        switch ($this->type) {
            case self::TYPE_NULL:
                return 0;
            case self::TYPE_INTEGER:
                return $this->integer;
            case self::TYPE_FLOAT:
                return $this->float;
            case self::TYPE_BOOLEAN:
                return $this->bool ? 1 : 0;
            case self::TYPE_STRING:
                if (!is_numeric($this->string)) {
                    throw new \LogicException("Cannot convert string to numeric");
                }
                if (((string)(int) $this->string) === $this->string) {
                    return (int) $this->string;
                }
                return (float) $this->string;
            case self::TYPE_OBJECT:
                return $this->objectToScalarString($vm, 'int')->toNumeric($vm);
        }
        throw new \TypeError(sprintf(
            'Unsupported operand types: %s',
            self::operandZendTypeName($this)
        ));
    }

    /** Zend zend_operators.c type name for operand TypeError messages (#3695). */
    private static function operandZendTypeName(Variable $var): string
    {
        switch ($var->type) {
            case self::TYPE_INTEGER:
                return 'int';
            case self::TYPE_FLOAT:
                return 'float';
            case self::TYPE_BOOLEAN:
                return 'bool';
            case self::TYPE_STRING:
                return 'string';
            case self::TYPE_NULL:
                return 'null';
            case self::TYPE_ARRAY:
                return 'array';
            case self::TYPE_OBJECT:
            case self::TYPE_ENUM_CASE:
                return 'object';
            default:
                return 'mixed';
        }
    }

    private static function numericOpOperatorSymbol(int $opCode): string
    {
        switch ($opCode) {
            case OpCode::TYPE_PLUS:
                return '+';
            case OpCode::TYPE_MINUS:
                return '-';
            case OpCode::TYPE_MUL:
                return '*';
            case OpCode::TYPE_DIV:
                return '/';
            case OpCode::TYPE_MODULO:
                return '%';
            case OpCode::TYPE_POW:
                return '**';
            default:
                return '?';
        }
    }

    private static function throwUnsupportedOperandTypes(int $opCode, Variable $left, Variable $right): void
    {
        throw new \TypeError(sprintf(
            'Unsupported operand types: %s %s %s',
            self::operandZendTypeName($left),
            self::numericOpOperatorSymbol($opCode),
            self::operandZendTypeName($right)
        ));
    }

    private static function operandsValidForNumericOp(Variable $left, Variable $right): bool
    {
        if (self::TYPE_ARRAY === $left->type || self::TYPE_ARRAY === $right->type) {
            return false;
        }
        if (self::TYPE_STRING === $left->type && !is_numeric($left->string)) {
            return false;
        }
        if (self::TYPE_STRING === $right->type && !is_numeric($right->string)) {
            return false;
        }
        if (self::TYPE_STRING_OFFSET === $left->type
            || self::TYPE_STRING_OFFSET === $right->type
            || self::TYPE_UNDEFINED === $left->type
            || self::TYPE_UNDEFINED === $right->type
            || self::TYPE_ENUM_CASE === $left->type
            || self::TYPE_ENUM_CASE === $right->type) {
            return false;
        }

        return true;
    }


    public function null(): void {
        $this->reset();
        $this->type = self::TYPE_NULL;
    }
    
    public function bool(bool $value): void {
        $this->reset();
        $this->type = self::TYPE_BOOLEAN;
        $this->bool = $value;
    }

    public function toBool(?\PHPCompiler\VM $vm = null): bool {
        if (self::TYPE_INDIRECT === $this->type) {
            return $this->indirect->toBool($vm);
        }
        TypedPropertyCheck::assertReadable($this);
        switch ($this->type) {
            case self::TYPE_NULL:
                return false;
            case self::TYPE_INTEGER:
                return 0 !== $this->integer;
            case self::TYPE_FLOAT:
                return 0.0 !== $this->float;
            case self::TYPE_BOOLEAN:
                return $this->bool;
            case self::TYPE_STRING:
                return '' !== $this->string && '0' !== $this->string;
            case self::TYPE_OBJECT:
                if (null === $vm) {
                    return true;
                }
                $object = $this->resolveIndirect();
                if (!$vm->hasInstanceMethod($object->object->class, '__tostring')) {
                    return true;
                }

                return $this->objectToScalarString($vm, 'bool')->toBool($vm);
        }
        throw new \LogicException("Cannot convert type {$this->type} to bool");
    }

    public function string(string $value): void {
        $this->reset();
        $this->type = self::TYPE_STRING;
        $this->string = $value;
    }

    public function toString(): string {
        $var = $this->resolveIndirect();
        TypedPropertyCheck::assertReadable($var);
        switch ($var->type) {
            case self::TYPE_STRING:
                return $var->string;
            case self::TYPE_INTEGER:
                return (string) $var->integer;
            case self::TYPE_FLOAT:
                return (string) $var->float;
            case self::TYPE_BOOLEAN:
                return $var->bool ? '1' : '';
            case self::TYPE_STRING_OFFSET:
                return $var->readStringOffset();
            case self::TYPE_NULL:
            case self::TYPE_UNDEFINED:
                return '';
            case self::TYPE_ARRAY:
                // todo: raise notice
                return 'Array';
            case self::TYPE_OBJECT:
                if (EnumCaseSupport::isEnumCase($var->object)) {
                    return EnumCaseSupport::toString($var->object);
                }

                return 'Object';
            case self::TYPE_ENUM_CASE:
                if (null !== $var->enumCase->enumClass->backedType) {
                    return $var->enumCase->backingValue->toString();
                }

                return $var->enumCase->caseName;
        }
        throw new \LogicException("Cannot convert type {$var->type} to string");
    }

    public function object(ObjectEntry $value): void {
        $this->reset();
        $this->type = self::TYPE_OBJECT;
        $this->object = $value;
    }

    public function enumCase(EnumCaseEntry $value): void
    {
        $this->reset();
        $this->type = self::TYPE_ENUM_CASE;
        $this->enumCase = $value;
    }

    public function toEnumCase(): EnumCaseEntry
    {
        switch ($this->type) {
            case self::TYPE_ENUM_CASE:
                return $this->enumCase;
            case self::TYPE_INDIRECT:
                return $this->indirect->toEnumCase();
        }
        throw new \LogicException("Cannot convert $this->type to enum case");
    }

    public function toObject(): ObjectEntry {
        if (self::TYPE_INDIRECT === $this->type) {
            return $this->indirect->toObject();
        }
        TypedPropertyCheck::assertReadable($this);
        if (self::TYPE_OBJECT === $this->type) {
            return $this->object;
        }
        throw new \LogicException("Cannot convert $this->type to Object");
    }

    public function indirect(Variable $value): void {
        $this->reset();
        $this->type = self::TYPE_INDIRECT;
        $this->indirect = $value;
    }

    public function reset(): void {
        $this->type = self::TYPE_NULL;
        $this->streamResource = false;
        unset($this->string);
        unset($this->integer);
        unset($this->float);
        unset($this->bool);
        unset($this->object);
        unset($this->enumCase);
        unset($this->indirect);
        unset($this->stringOffsetParent);
        unset($this->stringOffsetIndex);
        unset($this->stringOffsetReporter);
        unset($this->stringOffsetFile);
    }

    public function stringOffset(
        Variable $parent,
        int $index,
        ?ErrorReporter $reporter = null,
        ?string $file = null
    ): void {
        $this->reset();
        $this->type = self::TYPE_STRING_OFFSET;
        $this->stringOffsetParent = $parent;
        $this->stringOffsetIndex = $index;
        $this->stringOffsetReporter = $reporter;
        $this->stringOffsetFile = $file;
    }

    public function castFrom(int $type, self $var, ?\PHPCompiler\VM $vm = null) {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->castFrom($type, $var, $vm);
            $this->indirect->copyFrom($result);

            return;
        }
        $this->reset();
        $this->type = $type;
        switch ($type) {
            case Variable::TYPE_BOOLEAN:
                $this->bool = $var->toBool($vm);
                break;
            case self::CAST_NUMERIC:
                $number = $var->toNumeric($vm);
                if (is_int($number)) {
                    $this->castFrom(Variable::TYPE_INTEGER, $var, $vm);
                } else {
                    $this->castFrom(Variable::TYPE_FLOAT, $var, $vm);
                }
                break;
            case Variable::TYPE_INTEGER:
                $this->integer = $var->toInt($vm);
                break;
            case Variable::TYPE_FLOAT:
                $this->float = $var->toFloat($vm);
                break;
            case Variable::TYPE_STRING:
                $src = $var->resolveIndirect();
                if (self::TYPE_OBJECT === $src->type && null !== $vm) {
                    $this->string = $vm->castObjectToString($src->toObject());
                } else {
                    $this->string = $var->toString();
                }
                break;
            default:
                throw new \LogicException("Unsupported cast type $type");
        }
    }

    public function copyFrom(self $var): void {
        if ($this->type === self::TYPE_INDIRECT) {
            // always assign to the indirection
            $this->indirect->copyFrom($var);
            return;
        }
        while ($var->type === self::TYPE_INDIRECT) {
            // destroy the indirection
            $var = $var->indirect;
        }
        TypedPropertyCheck::assertReadable($var);
        if ($this->type === self::TYPE_STRING_OFFSET) {
            $this->writeStringOffset($var);

            return;
        }
        switch ($var->type) {
            case self::TYPE_NULL:
                $this->null();
                break;
            case self::TYPE_STRING:
                $this->string($var->string);
                break;
            case self::TYPE_INTEGER:
                $this->int($var->integer);
                $this->streamResource = $var->streamResource;
                break;
            case self::TYPE_FLOAT:
                $this->float($var->float);
                break;
            case self::TYPE_BOOLEAN:
                $this->bool($var->bool);
                break;
            case self::TYPE_OBJECT:
                $this->object($var->object);
                break;
            case self::TYPE_ENUM_CASE:
                $backing = new Variable();
                $backing->copyFrom($var->enumCase->backingValue);
                $this->enumCase(new EnumCaseEntry(
                    $var->enumCase->enumClass,
                    $var->enumCase->caseName,
                    $backing
                ));
                break;
            case self::TYPE_ARRAY:
                $this->array($var->array);
                break;
            default:
                var_dump($var);
                throw new \LogicException("Unsupported type copy: {$var->type}");
        }
    }

    public function identicalTo(Variable $other): bool {
        $self = $this->resolveIndirect();
        $other = $other->resolveIndirect();
        if ($self->type !== $other->type) {
            return false;
        }
        if (self::TYPE_OBJECT === $self->type) {
            return $self->object === $other->object;
        }
        if (self::TYPE_STRING === $self->type) {
            return $self->string === $other->string;
        }

        return $self->equals($other);
    }

    public function equals(Variable $other): bool {
        $self = $this;
restart:
        $pair = type_pair($self->type, $other->type);
        switch ($pair) {
            case TYPE_PAIR_INTEGER_INTEGER:
                return $self->integer === $other->integer;
            case TYPE_PAIR_FLOAT_FLOAT:
                return $self->float === $other->float;
            case TYPE_PAIR_OBJECT_OBJECT:
                return $self->object->looseEquals($other->object);
            case TYPE_PAIR_BOOLEAN_BOOLEAN:
                return $self->bool === $other->bool;
            case TYPE_PAIR_NULL_NULL:
                return true;
            case TYPE_PAIR_INTEGER_FLOAT:
                return ((float) $self->integer) === $other->float;
            case TYPE_PAIR_FLOAT_INTEGER:
                return $self->float === ((float) $other->integer);
            default:
                if ($self->type === self::TYPE_INDIRECT) {
                    $self = $self->indirect;
                    goto restart;
                } elseif ($other->type === self::TYPE_INDIRECT) {
                    $other = $other->indirect;
                    goto restart;
                }
                return $this->looseEqual($self, $other);
        }
        throw new \LogicException("Equals comparison between {$self->type} and {$other->type} not implemented");
    }

    /**
     * Zend compare_function: non-numeric strings compare as 0 against numbers (zend_operators.c).
     */
    private static function looseNumericFromString(string $s): int|float
    {
        if (!is_numeric($s)) {
            return 0;
        }
        if (((string) (int) $s) === $s) {
            return (int) $s;
        }

        return (float) $s;
    }

    private function looseEqual(Variable $self, Variable $other): bool {
        if ($self->type === self::TYPE_NULL) {
            switch ($other->type) {
                case self::TYPE_NULL:
                    return true;
                case self::TYPE_BOOLEAN:
                    return !$other->bool;
                case self::TYPE_INTEGER:
                    return 0 === $other->integer;
                case self::TYPE_STRING:
                    return '' === $other->string;
                case self::TYPE_FLOAT:
                    return 0.0 === $other->float;
                case self::TYPE_ARRAY:
                    return 0 === $other->toArray()->getNumElements();
                default:
                    return false;
            }
        }
        if ($other->type === self::TYPE_NULL) {
            return $this->looseEqual($other, $self);
        }
        if ($self->type === self::TYPE_BOOLEAN && $other->type === self::TYPE_INTEGER) {
            return ($other->integer !== 0) === $self->bool;
        }
        if ($self->type === self::TYPE_INTEGER && $other->type === self::TYPE_BOOLEAN) {
            return ($self->integer !== 0) === $other->bool;
        }
        if ($self->type === self::TYPE_STRING && $other->type === self::TYPE_INTEGER) {
            if ('' === $self->string) {
                return false;
            }

            return $other->integer == self::looseNumericFromString($self->string);
        }
        if ($self->type === self::TYPE_INTEGER && $other->type === self::TYPE_STRING) {
            if ('' === $other->string) {
                return false;
            }

            return $self->integer == self::looseNumericFromString($other->string);
        }
        if ($self->type === self::TYPE_STRING && $other->type === self::TYPE_FLOAT) {
            return $other->float == self::looseNumericFromString($self->string);
        }
        if ($self->type === self::TYPE_FLOAT && $other->type === self::TYPE_STRING) {
            return $self->float == self::looseNumericFromString($other->string);
        }
        if ($self->type === self::TYPE_STRING && $other->type === self::TYPE_BOOLEAN) {
            return $self->toBool() === $other->bool;
        }
        if ($self->type === self::TYPE_BOOLEAN && $other->type === self::TYPE_STRING) {
            return $other->toBool() === $self->bool;
        }
        if ($self->type === self::TYPE_STRING && $other->type === self::TYPE_STRING) {
            if (is_numeric($self->string) && is_numeric($other->string)) {
                return self::looseNumericFromString($self->string) == self::looseNumericFromString($other->string);
            }

            return $self->string == $other->string;
        }
        if ($self->type === self::TYPE_ARRAY && $other->type === self::TYPE_BOOLEAN) {
            return ($self->toArray()->getNumElements() === 0) !== $other->bool;
        }
        if ($other->type === self::TYPE_ARRAY && $self->type === self::TYPE_BOOLEAN) {
            return ($other->toArray()->getNumElements() === 0) !== $self->bool;
        }
        if ($self->type === self::TYPE_ARRAY && $other->type === self::TYPE_ARRAY) {
            return $self->toArray()->compareLooseEqual($other->toArray());
        }
        if ($self->type === self::TYPE_ARRAY || $other->type === self::TYPE_ARRAY) {
            return false;
        }
        try {
            return $self->toNumeric() == $other->toNumeric();
        } catch (\LogicException|\TypeError) {
            return false;
        }
    }

    /**
     * Zend cast_object: explicit scalar casts invoke __toString when defined (zend_operators.c).
     *
     * @param 'bool'|'int'|'float' $castKind
     */
    private function objectToScalarString(?\PHPCompiler\VM $vm, string $castKind): self
    {
        $var = $this->resolveIndirect();
        if (self::TYPE_OBJECT !== $var->type) {
            throw new \LogicException('Expected object operand for scalar cast');
        }
        $className = $var->object->class->name;
        if (null === $vm) {
            throw new \LogicException('VM required for explicit object scalar cast');
        }
        if (!$vm->hasInstanceMethod($var->object->class, '__tostring')) {
            if ('int' === $castKind) {
                throw new \TypeError("Object of class {$className} could not be converted to int");
            }
            if ('float' === $castKind) {
                throw new \TypeError("Object of class {$className} could not be converted to float");
            }
            throw new \TypeError("Object of class {$className} could not be converted to bool");
        }
        $str = $vm->invokeInstanceMethod($var->object, '__toString')->toString();
        $tmp = new self(self::TYPE_STRING);
        $tmp->string($str);

        return $tmp;
    }

    private static function throwObjectNumericCompareError(Variable $object): never
    {
        $var = $object->resolveIndirect();
        if (self::TYPE_OBJECT !== $var->type) {
            throw new \LogicException('Expected object operand for numeric compare error');
        }
        $className = $var->object->class->name;
        throw new \TypeError("Object of class {$className} could not be converted to number");
    }

    public function compareOp(int $opCode, Variable $left, Variable $right): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->compareOp($opCode, $left, $right);
            $this->indirect->copyFrom($result);

            return;
        }
        $this->reset();
restart:
        switch (type_pair($left->type, $right->type)) {
            case TYPE_PAIR_INTEGER_INTEGER:
                $this->bool($this->_compareOp($opCode, $left->integer, $right->integer));
                break;
            case TYPE_PAIR_INTEGER_FLOAT:
                $this->bool($this->_compareOp($opCode, $left->integer, $right->float));
                break;
            case TYPE_PAIR_FLOAT_INTEGER:
                $this->bool($this->_compareOp($opCode, $left->float, $right->integer));
                break;
            case TYPE_PAIR_FLOAT_FLOAT:
                $this->bool($this->_compareOp($opCode, $left->float, $right->float));
                break;
            case TYPE_PAIR_STRING_STRING:
                $this->bool($this->_compareOp($opCode, $left->string, $right->string));
                break;
            case TYPE_PAIR_BOOLEAN_BOOLEAN:
                $this->bool($this->_compareOp($opCode, $left->bool, $right->bool));
                break;
            case TYPE_PAIR_NULL_NULL:
                $this->bool($this->_compareOp($opCode, null, null));
                break;
            case TYPE_PAIR_OBJECT_OBJECT:
                self::throwObjectNumericCompareError($left);
            default:
                if ($left->type === self::TYPE_INDIRECT) {
                    $left = $left->indirect;
                    goto restart;
                } elseif ($right->type === self::TYPE_INDIRECT) {
                    $right = $right->indirect;
                    goto restart;
                } else {
                    $this->bool($this->_compareOp($opCode, $left->toNumeric(), $right->toNumeric()));
                }
        }
    }

    private function _compareOp(int $opCode, $left, $right): bool {
        switch ($opCode) {
            case OpCode::TYPE_IDENTICAL:
               return $left === $right;
            case OpCode::TYPE_GREATER:
                return $left > $right;
            case OpCode::TYPE_SMALLER:
               return $left < $right;
            case OpCode::TYPE_GREATER_OR_EQUAL:
                return $left >= $right;
            case OpCode::TYPE_SMALLER_OR_EQUAL:
                return $left <= $right;
            default:
                throw new \LogicException("Non-implemented numeric comparison operation $opCode");
        }
    }

    /** Alias for {@see compareSpaceship()} used by ObjectEntry property walks (#3691). */
    public static function compareSpaceship(Variable $left, Variable $right): int
    {
        return self::spaceshipCompare($left, $right);
    }

    public static function spaceshipCompare(Variable $left, Variable $right): int
    {
        $result = new self();
        $result->spaceshipOp($left, $right);

        return $result->integer;
    }

    public function spaceshipOp(Variable $left, Variable $right): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->spaceshipOp($left, $right);
            $this->indirect->copyFrom($result);

            return;
        }
        $this->reset();
restart:
        switch (type_pair($left->type, $right->type)) {
            case TYPE_PAIR_INTEGER_INTEGER:
                $this->int($this->_spaceship($left->integer, $right->integer));
                break;
            case TYPE_PAIR_INTEGER_FLOAT:
                $this->int($this->_spaceship($left->integer, $right->float));
                break;
            case TYPE_PAIR_FLOAT_INTEGER:
                $this->int($this->_spaceship($left->float, $right->integer));
                break;
            case TYPE_PAIR_FLOAT_FLOAT:
                $this->int($this->_spaceship($left->float, $right->float));
                break;
            case TYPE_PAIR_STRING_STRING:
                $cmp = strcmp($left->string, $right->string);
                $this->int($cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0));
                break;
            case TYPE_PAIR_BOOLEAN_BOOLEAN:
                $this->int($this->_spaceship((int) $left->bool, (int) $right->bool));
                break;
            case TYPE_PAIR_NULL_NULL:
                $this->int(0);
                break;
            case TYPE_PAIR_OBJECT_OBJECT:
                $this->int($left->object->compareSpaceship($right->object));
                break;
            case TYPE_PAIR_ARRAY_ARRAY:
                $this->int($left->array->compareSpaceship($right->array));
                break;
            default:
                if ($left->type === self::TYPE_INDIRECT) {
                    $left = $left->indirect;
                    goto restart;
                } elseif ($right->type === self::TYPE_INDIRECT) {
                    $right = $right->indirect;
                    goto restart;
                } else {
                    $this->int($this->_spaceship($left->toNumeric(), $right->toNumeric()));
                }
        }
    }

    private function _spaceship($left, $right): int {
        if ($left < $right) {
            return -1;
        }
        if ($left > $right) {
            return 1;
        }

        return 0;
    }

    public function bitwiseOp(int $opCode, Variable $left, Variable $right): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->bitwiseOp($opCode, $left, $right);
            $this->indirect->copyFrom($result);

            return;
        }
        $this->reset();
restart:
        $pair = type_pair($left->type, $right->type);
        if ($pair === TYPE_PAIR_INTEGER_INTEGER) {
            $result = $this->_bitwiseOp($opCode, $left->integer, $right->integer);        
            if (is_int($result)) {
                $this->int($result);
            } else {
                $this->float($result);
            }
        } elseif ($pair === TYPE_PAIR_INTEGER_FLOAT) {
            $this->float($this->_bitwiseOp($opCode, $left->integer, $right->float));
        } elseif ($pair === TYPE_PAIR_FLOAT_INTEGER) {
            $this->float($this->_bitwiseOp($opCode, $left->float, $right->integer));
        } elseif ($pair === TYPE_PAIR_FLOAT_FLOAT) {
            $this->float($this->_bitwiseOp($opCode, $left->float, $right->float));
        } elseif ($left->type === self::TYPE_INDIRECT) {
            $left = $left->indirect;
            goto restart;
        } elseif ($right->type === self::TYPE_INDIRECT) {
            $right = $right->indirect;
            goto restart;
        } else {
            $this->string($this->_bitwiseOp($opCode, $left->toString(), $right->toString()));
        }
    }

    private function _bitwiseOp(int $opCode, $left, $right) {
        switch ($opCode) {
            case OpCode::TYPE_BITWISE_AND:
               return $left & $right;
            case OpCode::TYPE_BITWISE_OR:
                return $left | $right;
            case OpCode::TYPE_BITWISE_XOR:
                return $left ^ $right;
            case OpCode::TYPE_SHIFT_LEFT:
                return (int) $left << (int) $right;
            case OpCode::TYPE_SHIFT_RIGHT:
                return (int) $left >> (int) $right;
            default:
                throw new \LogicException("Non-implemented bitwise operation $opCode");
        }
    }

    public function numericOp(int $opCode, Variable $left, Variable $right): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->numericOp($opCode, $left, $right);
            $this->indirect->copyFrom($result);

            return;
        }
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        if (OpCode::TYPE_PLUS === $opCode
            && self::TYPE_ARRAY === $left->type
            && self::TYPE_ARRAY === $right->type) {
            if ($this === $left) {
                $left->array->unionInPlace($right->array);
            } else {
                $this->array($left->array->unionCopy($right->array));
            }

            return;
        }
        if (!self::operandsValidForNumericOp($left, $right)) {
            self::throwUnsupportedOperandTypes($opCode, $left, $right);
        }
        // In-place ops (e.g. $i++ → PLUS($i,$i,1)) alias $this with an operand (#1228).
        if ($this === $left || $this === $right) {
            $this->storeNumericOp($opCode, $left->toNumeric(), $right->toNumeric());

            return;
        }
        $this->reset();
restart:
        $pair = type_pair($left->type, $right->type);
        if ($pair === TYPE_PAIR_INTEGER_INTEGER) {
            $result = $this->_numericOp($opCode, $left->integer, $right->integer);        
            if (is_int($result)) {
                $this->int($result);
            } else {
                $this->float($result);
            }
        } elseif ($pair === TYPE_PAIR_INTEGER_FLOAT) {
            $this->float($this->_numericOp($opCode, $left->integer, $right->float));
        } elseif ($pair === TYPE_PAIR_FLOAT_INTEGER) {
            $this->float($this->_numericOp($opCode, $left->float, $right->integer));
        } elseif ($pair === TYPE_PAIR_FLOAT_FLOAT) {
            $this->float($this->_numericOp($opCode, $left->float, $right->float));
        } elseif ($left->type === self::TYPE_INDIRECT) {
            $left = $left->indirect;
            goto restart;
        } elseif ($right->type === self::TYPE_INDIRECT) {
            $right = $right->indirect;
            goto restart;
        } else {
            $result = $this->_numericOp($opCode, $left->toNumeric(), $right->toNumeric());
            if (is_int($result)) {
                $this->int($result);
            } else {
                $this->float($result);
            }
        }
    }

    /**
     * ++/-- lowered as Plus/Minus(read, 1) with isIncDec (issue #3469).
     *
     * @see Zend/zend_operators.c increment_function() / decrement_function()
     */
    public function incDecOp(int $opCode, Variable $left, Variable $right): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->incDecOp($opCode, $left, $right);
            $this->indirect->copyFrom($result);

            return;
        }
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        $strVar = self::TYPE_STRING === $left->type ? $left : (self::TYPE_STRING === $right->type ? $right : null);
        if (null !== $strVar) {
            $this->applyStringIncDec($opCode, $strVar->toString());

            return;
        }
        if ($this === $left || $this === $right) {
            $this->storeNumericOp($opCode, $left->toNumeric(), $right->toNumeric());

            return;
        }
        $this->numericOp($opCode, $left, $right);
    }

    private function applyStringIncDec(int $opCode, string $str): void
    {
        if (OpCode::TYPE_PLUS === $opCode) {
            if (self::isNumericStringForIncDec($str)) {
                $this->storeNumericStringIncDec($str, 1);

                return;
            }
            $this->string(VmString::incrementStringOperator($str));

            return;
        }
        if (self::isNumericStringForIncDec($str)) {
            $this->storeNumericStringIncDec($str, -1);

            return;
        }
        $this->string($str);
    }

    private static function isNumericStringForIncDec(string $str): bool
    {
        return '' !== $str && is_numeric($str);
    }

    private function storeNumericStringIncDec(string $str, int $delta): void
    {
        if (str_contains($str, '.') || str_contains(strtolower($str), 'e')) {
            $this->float((float) $str + $delta);
        } else {
            $this->int((int) $str + $delta);
        }
    }

    private function storeNumericOp(int $opCode, $left, $right): void
    {
        $this->reset();
        $result = $this->_numericOp($opCode, $left, $right);
        if (is_int($result)) {
            $this->int($result);
        } else {
            $this->float($result);
        }
    }

    private function _numericOp(int $opCode, $left, $right) {
        switch ($opCode) {
            case OpCode::TYPE_PLUS:
               return $left + $right;
            case OpCode::TYPE_MINUS:
                return $left - $right;
            case OpCode::TYPE_MUL:
                return $left * $right;
            case OpCode::TYPE_DIV:
                return $left / $right;
            case OpCode::TYPE_MODULO:
                return $left % $right;
            case OpCode::TYPE_POW:
                if (is_int($left) && is_int($right)) {
                    return $left ** $right;
                }

                return \pow((float) $left, (float) $right);
            default:
                throw new \LogicException("Non-implemented numeric binary operation $opCode");
        }
    }

    /**
     * Zend increment_function() on a single value (issue #3552).
     */
    public function applyIncrement(): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $copy = new self();
            $copy->copyFrom($this->indirect);
            $copy->applyIncrement();
            $this->indirect->copyFrom($copy);

            return;
        }
        switch ($this->type) {
            case self::TYPE_BOOLEAN:
                return;
            case self::TYPE_NULL:
                $this->int(1);

                return;
            case self::TYPE_INTEGER:
                ++$this->integer;

                return;
            case self::TYPE_FLOAT:
                $this->float += 1;

                return;
            default:
                $one = new self();
                $one->int(1);
                $this->numericOp(OpCode::TYPE_PLUS, $this, $one);
        }
    }

    /**
     * Zend decrement_function() on a single value (issue #3552).
     */
    public function applyDecrement(): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $copy = new self();
            $copy->copyFrom($this->indirect);
            $copy->applyDecrement();
            $this->indirect->copyFrom($copy);

            return;
        }
        switch ($this->type) {
            case self::TYPE_BOOLEAN:
            case self::TYPE_NULL:
                return;
            case self::TYPE_INTEGER:
                --$this->integer;

                return;
            case self::TYPE_FLOAT:
                $this->float -= 1;

                return;
            default:
                $one = new self();
                $one->int(1);
                $this->numericOp(OpCode::TYPE_MINUS, $this, $one);
        }
    }

    public function unaryOp(int $opCode, Variable $expr): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->unaryOp($opCode, $expr);
            $this->indirect->copyFrom($result);

            return;
        }
        $this->reset();
restart:
        switch ($opCode) {
            case OpCode::TYPE_UNARY_PLUS:
                $this->castFrom(self::CAST_NUMERIC, $expr);
                return;
            case OpCode::TYPE_UNARY_MINUS:
                if ($expr->type === Variable::TYPE_INTEGER) {
                    $this->copyFrom($expr);
                    $this->integer *= -1;
                    return;
                } elseif($expr->type === Variable::TYPE_FLOAT) {
                    $this->copyFrom($expr);
                    $this->float *= -1.0;
                    return;
                } else {
                    $this->castFrom(self::CAST_NUMERIC, $expr);
                    goto restart;
                }
                break;
        }
        throw new \LogicException("UnaryOp $opCode not implemented for type $expr->type");
    }

    /**
     * Zend-style string byte index: negative offsets count from the end (PHP 7.1+).
     *
     * @return int|null byte index, or null when out of range (caller emits warning)
     */
    private function resolveStringOffsetByteIndex(int $rawIndex, int $len): ?int
    {
        $index = $rawIndex;
        if ($index < 0) {
            $index += $len;
        }
        if ($index < 0 || $index >= $len) {
            return null;
        }

        return $index;
    }

    private function readStringOffset(): string
    {
        $parent = $this->stringOffsetParent->resolveIndirect();
        if ($parent->type !== self::TYPE_STRING) {
            throw new \LogicException('String offset parent is not a string');
        }
        $str = $parent->string;
        $rawIndex = $this->stringOffsetIndex;
        $len = strlen($str);
        $index = $this->resolveStringOffsetByteIndex($rawIndex, $len);
        if (null === $index) {
            if (null !== $this->stringOffsetReporter) {
                $this->stringOffsetReporter->uninitializedStringOffset(
                    $rawIndex,
                    null,
                    null,
                    $this->stringOffsetFile
                );
            }

            return '';
        }

        return $str[$index];
    }

    private function writeStringOffset(self $value): void
    {
        $parent = $this->stringOffsetParent->resolveIndirect();
        if ($parent->type !== self::TYPE_STRING) {
            throw new \LogicException('String offset parent is not a string');
        }
        $str = $parent->string;
        $rawIndex = $this->stringOffsetIndex;
        $len = strlen($str);
        $index = $this->resolveStringOffsetByteIndex($rawIndex, $len);
        if (null === $index) {
            if (null !== $this->stringOffsetReporter) {
                $this->stringOffsetReporter->illegalStringOffset(
                    $rawIndex,
                    null,
                    null,
                    $this->stringOffsetFile
                );
            }

            return;
        }
        $byte = self::byteFromAssignValue($value);
        if ($index >= $len) {
            $str .= str_repeat("\0", $index - $len + 1);
        }
        $str[$index] = $byte;
        $parent->string($str);
    }

    private static function byteFromAssignValue(self $value): string
    {
        $value = $value->resolveIndirect();
        switch ($value->type) {
            case self::TYPE_STRING:
                $s = $value->string;

                return '' === $s ? '' : $s[0];
            case self::TYPE_INTEGER:
                return chr($value->integer & 0xff);
            case self::TYPE_NULL:
                return "\0";
            default:
                $s = $value->toString();

                return '' === $s ? '' : $s[0];
        }
    }
}

/** Precomputed (left * 256 + right) for JIT self-host bundle (no shift/mul in global init). */
const TYPE_PAIR_INTEGER_INTEGER = 257;
const TYPE_PAIR_INTEGER_FLOAT = 258;
const TYPE_PAIR_FLOAT_INTEGER = 513;
const TYPE_PAIR_FLOAT_FLOAT = 514;
const TYPE_PAIR_STRING_STRING = 1028;
const TYPE_PAIR_OBJECT_OBJECT = 1285;
const TYPE_PAIR_BOOLEAN_BOOLEAN = 771;
const TYPE_PAIR_NULL_NULL = 0;
const TYPE_PAIR_ARRAY_ARRAY = 1542;

function type_pair(int $left, int $right): int {
    return $left * 256 + $right;
}
