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

    /** @see Zend/zend_operators.c increment_function() / decrement_function() on TYPE_STRING offsets */
    public const STRING_OFFSET_INCDEC_ERROR = 'Cannot increment/decrement string offsets';
    /** Zend enum case object for E::Case fetches (#3420, #3554). */
    const TYPE_ENUM_CASE = 9;
    /** Writable ArrayAccess dimension ($obj[$key] assignment, #3331). */
    const TYPE_ARRAYACCESS_OFFSET = 10;
    /** Writable hooked property reference cell (#6426). */
    const TYPE_PROPERTY_HOOK_REF = 11;


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
    private ?Context $stringOffsetContext = null;
    private ?\PHPCompiler\Frame $stringOffsetFrame = null;
    private ?string $stringOffsetFile = null;
    private ArrayAccessDimension $arrayAccessDimension;
    private PropertyHookRef $propertyHookRef;


    public int $next = -1;

    public ?int $typeConstraint = null;
    public ?string $classConstraint = null;

    /** Scalar VM type members for union declared types (issue #169). */
    public ?array $unionTypeConstraints = null;

    /** Display label for property TypeError messages, e.g. `int|string`. */
    public ?string $declaredTypeLabel = null;

    /** Standalone `true`/`false` type hint — reject non-bool scalars (PHP 8.2+, issue #4784). */
    public ?string $literalBoolType = null;

    /** list&lt;T&gt; / array&lt;K,V&gt; shape when declaration used generic array syntax (#3705). */
    public ?GenericArrayTypeSpec $genericArrayTypeSpec = null;

    /** @var list<array{kind: string, interfaces?: list<string>, name?: string}>|null */
    public ?array $dnfArms = null;

    /** Set for instance properties so readonly-class writes can be enforced (issue #1360). */
    public ?ObjectEntry $objectPropertyOwner = null;

    public ?string $objectPropertyName = null;

    /** Declaring class (lowercase) for static property hook set dispatch (#4751). */
    public ?string $staticPropertyClassLc = null;

    /** Function-local typed static variable name for TypeError messages (#9998). */
    public ?string $functionStaticVarName = null;

    /** Stream handle from fopen()/similar; distinguishes handle ints from plain integers (#3519). */
    public bool $streamResource = false;

    public bool $dirResource = false;

    /** Stream filter brigade registry handle (#7089). */
    public bool $brigadeResource = false;

    /** Stream filter bucket registry handle (#7089). */
    public bool $bucketResource = false;

    /** stream_filter_append/prepend() filter resource (#3283). */
    public bool $streamFilterResource = false;

    /** proc_open() process handle (#3131). */
    public bool $procResource = false;

    /** Lvalue proxy for __set dispatch when the property slot does not exist (#146). */
    public ?ObjectEntry $magicSetTarget = null;

    public ?string $magicSetName = null;

    /** Temporary from __get; []= / dim-write must throw (#4673, zend_object_handlers.c). */
    public ?ObjectEntry $magicGetOverloadedTarget = null;

    public ?string $magicGetOverloadedName = null;

    /** Hooked property dim modify: flush set hook after assign through this container (#6775). */
    public bool $propertyHookDimWriteBackPending = false;

    /** Dim lvalue → hooked property container pending set-hook writeback (#6775). */
    public ?Variable $hookedPropertyDimWriteBackContainer = null;

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

    /** True when the slot carries a declared property/parameter type (not untyped `mixed`). */
    public function hasDeclaredTypeConstraint(): bool
    {
        return null !== $this->typeConstraint
            || null !== $this->dnfArms
            || null !== $this->unionTypeConstraints
            || null !== $this->genericArrayTypeSpec
            || null !== $this->literalBoolType;
    }

    public function resolveIndirect(): self {
        $var = $this;
        $seen = [];
        while ($var->type === self::TYPE_INDIRECT) {
            $id = \spl_object_id($var);
            if (isset($seen[$id])) {
                throw new \LogicException('Circular variable reference');
            }
            $seen[$id] = true;
            $var = $var->indirect;
        }
        return $var;
    }

    public function isIndirect(): bool
    {
        return self::TYPE_INDIRECT === $this->type;
    }

    /**
     * By-value foreach must not write through a leftover by-ref loop variable (#5419).
     */
    public function assignForeachByValue(self $value): void
    {
        if (self::TYPE_INDIRECT === $this->type) {
            $this->reset();
        }
        $this->copyFrom($value);
    }

    public function newArray(): HashTable {
        $this->array(new HashTable);
        return $this->array;
    }

    public function array(HashTable $ht): void {
        $this->releaseTrackedMemory();
        $this->releaseArrayRef();
        $this->resetScalars();
        $this->type = self::TYPE_ARRAY;
        $this->streamResource = false;
        $this->dirResource = false;
        $this->brigadeResource = false;
        $this->bucketResource = false;
        $this->streamFilterResource = false;
        $this->procResource = false;
        $this->array = $ht;
        MemoryAccounting::noteBytes(MemoryAccounting::estimateArrayBytesForTable($ht));
    }

    /**
     * Detach from a shared array HashTable before assignment (Zend zval separation).
     */
    public function separateArrayForWrite(): void
    {
        if (self::TYPE_INDIRECT === $this->type) {
            $this->indirect->separateArrayForWrite();

            return;
        }
        if (self::TYPE_ARRAY !== $this->type || !isset($this->array)) {
            return;
        }
        if (!$this->array->needsSeparate()) {
            return;
        }
        $this->array->delRef();
        $this->array = $this->array->duplicate();
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
        $this->dirResource = false;
        $this->brigadeResource = false;
        $this->bucketResource = false;
        $this->streamFilterResource = false;
        $this->procResource = false;
    }

    public function streamHandle(int $value, ?Context $ctx = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $this->indirect->streamHandle($value, $ctx);

            return;
        }
        if (null !== $ctx) {
            ResourceSupport::wrap($this, $value, ResourceState::KIND_STREAM, $ctx);

            return;
        }
        $this->legacyStreamHandle($value);
    }

    public function dirHandle(int $value, ?Context $ctx = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $this->indirect->dirHandle($value, $ctx);

            return;
        }
        if (null !== $ctx) {
            ResourceSupport::wrap($this, $value, ResourceState::KIND_DIR, $ctx);

            return;
        }
        $this->legacyDirHandle($value);
    }

    public function brigadeHandle(int $value, ?Context $ctx = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $this->indirect->brigadeHandle($value, $ctx);

            return;
        }
        if (null !== $ctx) {
            ResourceSupport::wrap($this, $value, ResourceState::KIND_BRIGADE, $ctx);

            return;
        }
        $this->legacyBrigadeHandle($value);
    }

    public function bucketHandle(int $value, ?Context $ctx = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $this->indirect->bucketHandle($value, $ctx);

            return;
        }
        if (null !== $ctx) {
            ResourceSupport::wrap($this, $value, ResourceState::KIND_BUCKET, $ctx);

            return;
        }
        $this->legacyBucketHandle($value);
    }

    public function streamFilterHandle(int $value, ?Context $ctx = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $this->indirect->streamFilterHandle($value, $ctx);

            return;
        }
        if (null !== $ctx) {
            ResourceSupport::wrap($this, $value, ResourceState::KIND_STREAM_FILTER, $ctx);

            return;
        }
        $this->legacyStreamFilterHandle($value);
    }

    public function processHandle(int $value, ?Context $ctx = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $this->indirect->processHandle($value, $ctx);

            return;
        }
        if (null !== $ctx) {
            ResourceSupport::wrap($this, $value, ResourceState::KIND_PROCESS, $ctx);

            return;
        }
        $this->legacyProcessHandle($value);
    }

    public function legacyStreamHandle(int $value): void
    {
        $this->int($value);
        $this->streamResource = true;
        $this->dirResource = false;
        $this->brigadeResource = false;
        $this->bucketResource = false;
        $this->streamFilterResource = false;
        $this->procResource = false;
    }

    public function legacyDirHandle(int $value): void
    {
        $this->int($value);
        $this->dirResource = true;
        $this->streamResource = false;
        $this->brigadeResource = false;
        $this->bucketResource = false;
        $this->streamFilterResource = false;
        $this->procResource = false;
    }

    public function legacyBrigadeHandle(int $value): void
    {
        $this->int($value);
        $this->brigadeResource = true;
        $this->streamResource = false;
        $this->dirResource = false;
        $this->bucketResource = false;
        $this->streamFilterResource = false;
        $this->procResource = false;
    }

    public function legacyBucketHandle(int $value): void
    {
        $this->int($value);
        $this->bucketResource = true;
        $this->streamResource = false;
        $this->dirResource = false;
        $this->brigadeResource = false;
        $this->streamFilterResource = false;
    }

    public function legacyStreamFilterHandle(int $value): void
    {
        $this->int($value);
        $this->streamFilterResource = true;
        $this->streamResource = false;
        $this->dirResource = false;
        $this->brigadeResource = false;
        $this->bucketResource = false;
        $this->procResource = false;
    }

    public function legacyProcessHandle(int $value): void
    {
        $this->int($value);
        $this->procResource = true;
        $this->streamResource = false;
        $this->dirResource = false;
        $this->brigadeResource = false;
        $this->bucketResource = false;
        $this->streamFilterResource = false;
    }

    public function isStreamResource(): bool
    {
        return ResourceSupport::isStreamResource($this);
    }

    public function isDirResource(): bool
    {
        return ResourceSupport::isDirResource($this);
    }

    public function isBrigadeResource(): bool
    {
        return ResourceSupport::isBrigadeResource($this);
    }

    public function isBucketResource(): bool
    {
        return ResourceSupport::isBucketResource($this);
    }

    public function isStreamFilterResource(): bool
    {
        return ResourceSupport::isStreamFilterResource($this);
    }

    public function isProcessResource(): bool
    {
        return ResourceSupport::isProcessResource($this);
    }

    public function isVmResource(): bool
    {
        return ResourceSupport::isVmResource($this);
    }

    /**
     * Zend zend_compare_resources: stream/dir handles compare by registry id, not bare int (#4699).
     *
     * @return bool|null null when neither operand is a VM resource tag
     */
    private static function compareVmResources(Variable $left, Variable $right): ?bool
    {
        return ResourceSupport::compareResources($left, $right);
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
            case self::TYPE_ARRAY:
                return $this->toArray()->getNumElements() > 0 ? 1 : 0;
            case self::TYPE_OBJECT:
                if (ResourceSupport::isResourceObject($this->toObject())) {
                    $handle = ResourceSupport::resolveHandle($this);
                    if (null !== $handle) {
                        return $handle;
                    }
                }
                if (EnumCaseSupport::isEnumCase($this->toObject())) {
                    $enumInt = EnumCaseSupport::tryCastToInt($this, $vm?->context);
                    if (null !== $enumInt) {
                        return $enumInt;
                    }
                }

                return $this->objectToScalarString($vm, 'int')->toInt($vm);
            case self::TYPE_ENUM_CASE:
                $enumInt = EnumCaseSupport::tryCastToInt($this, $vm?->context);
                if (null !== $enumInt) {
                    return $enumInt;
                }
                break;
            case self::TYPE_ARRAYACCESS_OFFSET:
                return $this->arrayAccessDimension->read()->toInt($vm);
            case self::TYPE_PROPERTY_HOOK_REF:
                return $this->propertyHookRef->read()->toInt($vm);
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
            case self::TYPE_ARRAY:
                return $this->toArray()->getNumElements() > 0 ? 1.0 : 0.0;
            case self::TYPE_OBJECT:
                if (EnumCaseSupport::isEnumCase($this->toObject())) {
                    $enumFloat = EnumCaseSupport::tryCastToFloat($this, $vm?->context);
                    if (null !== $enumFloat) {
                        return $enumFloat;
                    }
                }

                return $this->objectToScalarString($vm, 'float')->toFloat($vm);
            case self::TYPE_ENUM_CASE:
                $enumFloat = EnumCaseSupport::tryCastToFloat($this, $vm?->context);
                if (null !== $enumFloat) {
                    return $enumFloat;
                }
                break;
            case self::TYPE_PROPERTY_HOOK_REF:
                return $this->propertyHookRef->read()->toFloat($vm);
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

    /** Zend zend_operators.c type name for operand TypeError messages (#3695, #4811, #6236). */
    private static function operandZendTypeName(Variable $var): string
    {
        return EnumCaseSupport::typeNameForVariable($var);
    }

    /** Enum case operands use the enum type name in unsupported-op messages (zend_operators.c). */
    private static function operandEnumClassName(Variable $var): ?string
    {
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);

        return null !== $enumClass ? $enumClass->name : null;
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
            case OpCode::TYPE_SHIFT_LEFT:
                return '<<';
            case OpCode::TYPE_SHIFT_RIGHT:
                return '>>';
            case OpCode::TYPE_BITWISE_AND:
                return '&';
            case OpCode::TYPE_BITWISE_OR:
                return '|';
            case OpCode::TYPE_BITWISE_XOR:
                return '^';
            default:
                return '?';
        }
    }

    private static function operandsValidForBitwiseOp(Variable $left, Variable $right): bool
    {
        if (self::TYPE_ARRAY === $left->type || self::TYPE_ARRAY === $right->type) {
            return false;
        }
        if (self::TYPE_STRING_OFFSET === $left->type
            || self::TYPE_STRING_OFFSET === $right->type
            || self::TYPE_UNDEFINED === $left->type
            || self::TYPE_UNDEFINED === $right->type
            || self::isEnumCaseOperand($left)
            || self::isEnumCaseOperand($right)) {
            return false;
        }

        return true;
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

    /**
     * @return int|float
     */
    private static function numericForArithmeticOp(
        int $opCode,
        Variable $operand,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ) {
        try {
            return $operand->toNumericForArithmetic($vm, $frame);
        } catch (\TypeError) {
            self::throwUnsupportedOperandTypes($opCode, $left, $right);
        }
    }

    private static function operandsValidForNumericOp(Variable $left, Variable $right): bool
    {
        if (self::TYPE_ARRAY === $left->type || self::TYPE_ARRAY === $right->type) {
            return false;
        }
        if (self::TYPE_STRING_OFFSET === $left->type
            || self::TYPE_STRING_OFFSET === $right->type
            || self::TYPE_UNDEFINED === $left->type
            || self::TYPE_UNDEFINED === $right->type
            || self::isEnumCaseOperand($left)
            || self::isEnumCaseOperand($right)) {
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
            case self::TYPE_UNDEFINED:
                return false;
            case self::TYPE_INTEGER:
                return 0 !== $this->integer;
            case self::TYPE_FLOAT:
                return 0.0 !== $this->float;
            case self::TYPE_BOOLEAN:
                return $this->bool;
            case self::TYPE_STRING:
                return '' !== $this->string && '0' !== $this->string;
            case self::TYPE_ARRAY:
                return $this->toArray()->getNumElements() > 0;
            case self::TYPE_OBJECT:
                if (null === $vm) {
                    return true;
                }
                $object = $this->resolveIndirect();
                if (!$vm->hasInstanceMethod($object->object->class, '__tostring')) {
                    return true;
                }

                return $this->objectToScalarString($vm, 'bool')->toBool($vm);
            case self::TYPE_ENUM_CASE:
                return true;
            case self::TYPE_PROPERTY_HOOK_REF:
                return $this->propertyHookRef->read()->toBool($vm);
        }
        throw new \LogicException("Cannot convert type {$this->type} to bool");
    }

    public function string(string $value): void {
        $this->reset();
        $this->type = self::TYPE_STRING;
        $this->string = $value;
        MemoryAccounting::noteBytes(strlen($value));
    }

    /** Read string scalar when assigned; null for typed prototypes / unset slots (#6357). */
    public function optionalScalarString(): ?string
    {
        $var = $this->resolveIndirect();
        if (self::TYPE_STRING !== $var->type || !isset($var->string)) {
            return null;
        }

        return $var->string;
    }

    /** Read int scalar when assigned; null for typed prototypes / unset slots (#6357). */
    public function optionalScalarInt(): ?int
    {
        $var = $this->resolveIndirect();
        if (self::TYPE_INTEGER !== $var->type || !isset($var->integer)) {
            return null;
        }

        return $var->integer;
    }

    public function toString(?\PHPCompiler\VM $vm = null, ?\PHPCompiler\Frame $frame = null): string {
        $var = $this->resolveIndirect();
        TypedPropertyCheck::assertReadable($var);
        switch ($var->type) {
            case self::TYPE_STRING:
                return $var->string;
            case self::TYPE_INTEGER:
                if ($var->isVmResource()) {
                    return 'Resource id #'.$var->integer;
                }

                return (string) $var->integer;
            case self::TYPE_FLOAT:
                return (string) $var->float;
            case self::TYPE_BOOLEAN:
                return $var->bool ? '1' : '';
            case self::TYPE_STRING_OFFSET:
                return $var->readStringOffset();
            case self::TYPE_ARRAYACCESS_OFFSET:
                return $var->arrayAccessDimension->read()->toString();
            case self::TYPE_PROPERTY_HOOK_REF:
                return $var->propertyHookRef->read()->toString($vm, $frame);
            case self::TYPE_NULL:
            case self::TYPE_UNDEFINED:
                return '';
            case self::TYPE_ARRAY:
                self::emitArrayToStringWarning($vm, $frame);
                return 'Array';
            case self::TYPE_OBJECT:
                if (ResourceSupport::isResourceObject($var->object)) {
                    $handle = ResourceSupport::resolveHandle($var);

                    return 'Resource id #'.(null !== $handle ? $handle : $var->object->id);
                }
                if (EnumCaseSupport::isEnumCase($var->object)) {
                    throw new \Error(
                        'Object of class '.$var->object->class->name.' could not be converted to string'
                    );
                }
                $typeString = ReflectionTypeSupport::tryObjectTypeString($var->object);
                if (null !== $typeString) {
                    return $typeString;
                }

                return 'Object';
            case self::TYPE_ENUM_CASE:
                throw new \Error(
                    'Object of class '.$var->enumCase->enumClass->name.' could not be converted to string'
                );
        }
        throw new \LogicException("Cannot convert type {$var->type} to string");
    }

    public function object(ObjectEntry $value): void {
        if (self::TYPE_OBJECT === $this->type && isset($this->object) && $this->object->id === $value->id) {
            return;
        }
        $this->reset();
        $this->type = self::TYPE_OBJECT;
        $this->object = $value;
        ObjectLifetime::addRef($value);
    }

    /**
     * Store an object without incrementing refcount (WeakReference target slot, #5089).
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_weakrefs.c
     */
    public function weakObject(ObjectEntry $value): void
    {
        if (self::TYPE_OBJECT === $this->type && isset($this->object) && $this->object->id === $value->id) {
            return;
        }
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

    public function directIndirectTarget(): ?self
    {
        if (self::TYPE_INDIRECT !== $this->type) {
            return null;
        }

        return $this->indirect;
    }

    public function reset(): void {
        $this->releaseTrackedMemory();
        if (self::TYPE_OBJECT === $this->type && isset($this->object)) {
            ObjectLifetime::releaseRef($this->object);
        }
        $this->releaseArrayRef();
        $this->resetScalars();
        $this->type = self::TYPE_NULL;
        $this->streamResource = false;
        $this->dirResource = false;
        $this->brigadeResource = false;
        $this->bucketResource = false;
        $this->streamFilterResource = false;
        $this->procResource = false;
    }

    /** Drop emalloc-tracked bytes before replacing or clearing this slot (#7310). */
    public function releaseTrackedMemory(): void
    {
        if (self::TYPE_INDIRECT === $this->type) {
            $this->indirect->releaseTrackedMemory();

            return;
        }
        if (self::TYPE_STRING === $this->type && isset($this->string)) {
            MemoryAccounting::noteBytes(-strlen($this->string));

            return;
        }
        if (self::TYPE_ARRAY === $this->type && isset($this->array)) {
            MemoryAccounting::noteBytes(-MemoryAccounting::estimateArrayBytesForTable($this->array));
        }
    }

    private function releaseArrayRef(): void
    {
        if (self::TYPE_ARRAY === $this->type && isset($this->array)) {
            $this->array->delRef();
            unset($this->array);
        }
    }

    private function resetScalars(): void
    {
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
        unset($this->stringOffsetContext);
        unset($this->stringOffsetFrame);
        unset($this->stringOffsetFile);
        unset($this->arrayAccessDimension);
        unset($this->propertyHookRef);
    }

    public function arrayAccessDimension(ArrayAccessDimension $dimension): void
    {
        $this->reset();
        $this->type = self::TYPE_ARRAYACCESS_OFFSET;
        $this->arrayAccessDimension = $dimension;
    }

    public function isArrayAccessOffset(): bool
    {
        return self::TYPE_ARRAYACCESS_OFFSET === $this->type;
    }

    /** Value from offsetGet for nested read ($obj[$k][$j]) without indirect write (#3446). */
    public function readArrayAccessOffsetValue(): self
    {
        if (self::TYPE_ARRAYACCESS_OFFSET !== $this->type) {
            throw new \LogicException('Not an ArrayAccess offset');
        }

        return $this->arrayAccessDimension->read()->resolveIndirect();
    }

    public function arrayAccessOffsetClassName(): string
    {
        if (self::TYPE_ARRAYACCESS_OFFSET !== $this->type) {
            throw new \LogicException('Not an ArrayAccess offset');
        }

        return $this->arrayAccessDimension->declaringClassName();
    }

    public function propertyHookRef(PropertyHookRef $ref): void
    {
        $this->reset();
        $this->type = self::TYPE_PROPERTY_HOOK_REF;
        $this->propertyHookRef = $ref;
    }

    public function isPropertyHookRef(): bool
    {
        return self::TYPE_PROPERTY_HOOK_REF === $this->type;
    }

    public function readPropertyHookRefValue(): Variable
    {
        if (self::TYPE_PROPERTY_HOOK_REF !== $this->type) {
            throw new \LogicException('Not a property hook reference');
        }

        return $this->propertyHookRef->read()->resolveIndirect();
    }

    public function propertyHookRefWriteLvalue(): Variable
    {
        if (self::TYPE_PROPERTY_HOOK_REF !== $this->type) {
            throw new \LogicException('Not a property hook reference');
        }

        return $this->propertyHookRef->writeLvalue();
    }

    /**
     * Zend string offset index: float emits "String offset cast occurred" then truncates.
     */
    public static function stringOffsetIndexFromDim(
        self $dim,
        ?ErrorReporter $reporter = null,
        ?Context $context = null,
        ?\PHPCompiler\Frame $frame = null,
        ?string $file = null
    ): int {
        $dim = $dim->resolveIndirect();
        if (self::TYPE_FLOAT === $dim->type) {
            if (null !== $reporter) {
                $reporter->stringOffsetCastOccurred($context, $frame, $file);
            }

            return (int) $dim->float;
        }

        return $dim->toInt();
    }

    /**
     * isset()/empty() on string offsets: in-bounds true, OOB false, no uninitialized warning (#5307).
     */
    public static function stringOffsetIsSetFromDim(
        self $container,
        self $dim,
        ?ErrorReporter $reporter = null,
        ?Context $context = null,
        ?\PHPCompiler\Frame $frame = null,
        ?string $file = null
    ): bool {
        $container = $container->resolveIndirect();
        if (self::TYPE_STRING !== $container->type) {
            return false;
        }
        $rawIndex = self::stringOffsetIndexFromDim($dim, $reporter, $context, $frame, $file);
        $len = strlen($container->string);
        $index = $rawIndex;
        if ($index < 0) {
            $index += $len;
        }

        return $index >= 0 && $index < $len;
    }

    public function stringOffset(
        Variable $parent,
        int $index,
        ?ErrorReporter $reporter = null,
        ?Context $context = null,
        ?\PHPCompiler\Frame $frame = null,
        ?string $file = null
    ): void {
        $this->reset();
        $this->type = self::TYPE_STRING_OFFSET;
        $this->stringOffsetParent = $parent;
        $this->stringOffsetIndex = $index;
        $this->stringOffsetReporter = $reporter;
        $this->stringOffsetContext = $context;
        $this->stringOffsetFrame = $frame;
        $this->stringOffsetFile = $file;
    }

    public function castFrom(int $type, self $var, ?\PHPCompiler\VM $vm = null, ?\PHPCompiler\Frame $frame = null) {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->castFrom($type, $var, $vm, $frame);
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
                $src = $var->resolveIndirect();
                $enumInt = EnumCaseSupport::tryCastToInt($src, $vm?->context, $frame);
                if (null !== $enumInt) {
                    $this->integer = $enumInt;
                    break;
                }
                $this->integer = $var->toInt($vm);
                break;
            case Variable::TYPE_FLOAT:
                $src = $var->resolveIndirect();
                $enumFloat = EnumCaseSupport::tryCastToFloat($src, $vm?->context, $frame);
                if (null !== $enumFloat) {
                    $this->float = $enumFloat;
                    break;
                }
                $this->float = $var->toFloat($vm);
                break;
            case Variable::TYPE_STRING:
                $src = $var->resolveIndirect();
                if (self::TYPE_ENUM_CASE === $src->type) {
                    throw new \Error(
                        'Object of class '.$src->toEnumCase()->enumClass->name.' could not be converted to string'
                    );
                }
                if (self::TYPE_OBJECT === $src->type && null !== $vm) {
                    $this->string = $vm->castObjectToString($src->toObject());
                } else {
                    $this->string = $var->toString($vm, $frame);
                }
                break;
            default:
                throw new \LogicException("Unsupported cast type $type");
        }
    }

    /**
     * Shallow property copy for {@see ObjectEntry::cloneShallow()} — skips typed-property
     * read guards so uninitialized slots clone like Zend zend_objects_clone_obj (#4245).
     */
    public function copyFromForClone(self $var): void
    {
        if (self::TYPE_INDIRECT === $this->type) {
            $this->indirect->copyFromForClone($var);

            return;
        }
        while (self::TYPE_INDIRECT === $var->type) {
            $var = $var->indirect;
        }
        if (TypedPropertyCheck::isUninitialized($var)) {
            $owner = $this->objectPropertyOwner;
            $name = $this->objectPropertyName;
            $this->reset();
            $this->type = self::TYPE_UNDEFINED;
            $this->objectPropertyOwner = $owner;
            $this->objectPropertyName = $name;

            return;
        }
        $this->copyFrom($var);
    }

    /**
     * Per-class clone of trait static property storage without reading uninitialized typed slots (#6624).
     */
    public function copyUninitializedStaticPropertySlot(self $source): void
    {
        $source = $source->resolveIndirect();
        $this->reset();
        $this->type = self::TYPE_UNDEFINED;
        $this->typeConstraint = $source->typeConstraint;
        $this->classConstraint = $source->classConstraint;
        $this->unionTypeConstraints = $source->unionTypeConstraints;
        $this->declaredTypeLabel = $source->declaredTypeLabel;
        $this->literalBoolType = $source->literalBoolType;
        $this->genericArrayTypeSpec = $source->genericArrayTypeSpec;
        $this->dnfArms = $source->dnfArms;
        $this->objectPropertyName = $source->objectPropertyName;
        $this->staticPropertyClassLc = $source->staticPropertyClassLc;
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
        if ($this->type === self::TYPE_ARRAYACCESS_OFFSET) {
            $this->arrayAccessDimension->write($var);

            return;
        }
        if ($this->type === self::TYPE_PROPERTY_HOOK_REF) {
            $this->propertyHookRef->write($var);

            return;
        }
        switch ($var->type) {
            case self::TYPE_NULL:
                $this->null();
                break;
            case self::TYPE_STRING:
                $this->string($var->string);
                break;
            case self::TYPE_STRING_OFFSET:
                $this->string($var->toString());
                break;
            case self::TYPE_PROPERTY_HOOK_REF:
                $this->copyFrom($var->propertyHookRef->read());
                break;
            case self::TYPE_INTEGER:
                $this->int($var->integer);
                $this->streamResource = $var->streamResource;
                $this->dirResource = $var->dirResource;
                $this->brigadeResource = $var->brigadeResource;
                $this->bucketResource = $var->bucketResource;
                $this->procResource = $var->procResource;
                break;
            case self::TYPE_FLOAT:
                $this->float($var->float);
                break;
            case self::TYPE_BOOLEAN:
                $this->bool($var->bool);
                break;
            case self::TYPE_OBJECT:
                if (!isset($var->object)) {
                    $this->null();
                    break;
                }
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
                if (self::TYPE_ARRAY === $this->type && isset($this->array) && $this->array === $var->array) {
                    break;
                }
                $owner = $this->objectPropertyOwner;
                $propName = $this->objectPropertyName;
                $staticClass = $this->staticPropertyClassLc;
                $this->releaseArrayRef();
                $this->resetScalars();
                $var->array->addRef();
                $this->type = self::TYPE_ARRAY;
                $this->streamResource = false;
                $this->dirResource = false;
                $this->brigadeResource = false;
                $this->bucketResource = false;
                $this->streamFilterResource = false;
                $this->procResource = false;
                $this->array = $var->array;
                $this->objectPropertyOwner = $owner;
                $this->objectPropertyName = $propName;
                $this->staticPropertyClassLc = $staticClass;
                break;
            case self::TYPE_ENUM_CASE:
                $this->enumCase(new EnumCaseEntry(
                    $var->enumCase->enumClass,
                    $var->enumCase->caseName,
                    clone $var->enumCase->backingValue,
                ));
                break;
            case self::TYPE_UNDEFINED:
                $owner = $this->objectPropertyOwner;
                $propName = $this->objectPropertyName;
                $staticClass = $this->staticPropertyClassLc;
                $this->reset();
                $this->type = self::TYPE_UNDEFINED;
                $this->objectPropertyOwner = $owner;
                $this->objectPropertyName = $propName;
                $this->staticPropertyClassLc = $staticClass;
                break;
            default:
                throw new \LogicException("Unsupported type copy: {$var->type}");
        }
    }

    /**
     * Deep copy used when duplicating array storage (COW separation, zend_array_dup).
     */
    public function duplicateFrom(self $var): void
    {
        if (self::TYPE_INDIRECT === $this->type) {
            $this->indirect->duplicateFrom($var);

            return;
        }
        // Share live reference cells when duplicating array buckets (Zend zend_array_dup, #6426/#6727).
        if (self::TYPE_INDIRECT === $var->type) {
            $this->indirect($var->indirect);

            return;
        }
        if (self::TYPE_PROPERTY_HOOK_REF === $var->type) {
            $this->propertyHookRef($var->propertyHookRef);

            return;
        }
        TypedPropertyCheck::assertReadable($var);
        if (self::TYPE_ARRAY === $var->type) {
            $this->array($var->array->duplicate());

            return;
        }
        $this->copyFrom($var);
    }

    public function identicalTo(Variable $other): bool {
        $self = $this->resolveIndirect();
        $other = $other->resolveIndirect();
        if (self::isEnumCaseOperand($self) && self::isEnumCaseOperand($other)) {
            return EnumCaseSupport::enumCaseVariablesEqual($self, $other);
        }
        // Zend compare_function: enum case vs backing scalar is never equal (#5798).
        if (self::isEnumCaseOperand($self) || self::isEnumCaseOperand($other)) {
            return false;
        }
        if ($self->type !== $other->type) {
            return false;
        }
        if (self::TYPE_OBJECT === $self->type) {
            $resourceCmp = self::compareVmResources($self, $other);
            if (null !== $resourceCmp) {
                return $resourceCmp;
            }

            return $self->object === $other->object;
        }
        if (self::TYPE_STRING === $self->type) {
            return $self->string === $other->string;
        }
        $resourceCmp = self::compareVmResources($self, $other);
        if (null !== $resourceCmp) {
            return $resourceCmp;
        }

        return $self->equals($other);
    }

    public function equals(Variable $other): bool {
        $self = $this;
restart:
        $pair = type_pair($self->type, $other->type);
        switch ($pair) {
            case TYPE_PAIR_INTEGER_INTEGER:
                $resourceCmp = self::compareVmResources($self, $other);
                if (null !== $resourceCmp) {
                    return $resourceCmp;
                }

                return $self->integer === $other->integer;
            case TYPE_PAIR_FLOAT_FLOAT:
                return $self->float === $other->float;
            case TYPE_PAIR_OBJECT_OBJECT:
                $resourceCmp = self::compareVmResources($self, $other);
                if (null !== $resourceCmp) {
                    return $resourceCmp;
                }

                return $self->object->looseEquals($other->object);
            case TYPE_PAIR_ENUM_CASE_ENUM_CASE:
                return $self->enumCase->enumClass === $other->enumCase->enumClass
                    && $self->enumCase->caseName === $other->enumCase->caseName;
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
                } elseif (self::isEnumCaseOperand($self) && self::isEnumCaseOperand($other)) {
                    return EnumCaseSupport::enumCaseVariablesEqual($self, $other);
                } elseif (self::isEnumCaseOperand($self) || self::isEnumCaseOperand($other)) {
                    return false;
                }
                return $this->looseEqual($self, $other);
        }
        throw new \LogicException("Equals comparison between {$self->type} and {$other->type} not implemented");
    }

    /**
     * Unary {@see OpCode::TYPE_UNARY_PLUS}/{@see OpCode::TYPE_UNARY_MINUS}: string operands use
     * zend_is_numeric_string / numeric-prefix coercion (zend_operators.c, #4723, #5083, #5427).
     */
    private static function coerceUnaryPlusOperand(
        Variable $expr,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): int|float {
        $expr = $expr->resolveIndirect();
        TypedPropertyCheck::assertReadable($expr);
        switch ($expr->type) {
            case self::TYPE_NULL:
                return 0;
            case self::TYPE_INTEGER:
                return $expr->integer;
            case self::TYPE_FLOAT:
                return $expr->float;
            case self::TYPE_BOOLEAN:
                return $expr->bool ? 1 : 0;
            case self::TYPE_STRING:
                try {
                    [$value, $warn] = self::parseStringForArithmetic($expr->string);
                } catch (\LogicException) {
                    throw new \TypeError(sprintf(
                        'Unsupported operand types: %s * int',
                        self::operandZendTypeName($expr)
                    ));
                }
                if ($warn) {
                    self::warnNonNumericValue($vm, $frame);
                }

                return $value;
            case self::TYPE_OBJECT:
                return self::coerceUnaryPlusOperand(
                    $expr->objectToScalarString($vm, 'int'),
                    $vm,
                    $frame
                );
        }
        throw new \TypeError(sprintf(
            'Unsupported operand types: %s',
            self::operandZendTypeName($expr)
        ));
    }

    /**
     * Zend _convert_to_string() array branch (zend_operators.c, issue #5266).
     */
    private static function emitArrayToStringWarning(?\PHPCompiler\VM $vm, ?\PHPCompiler\Frame $frame): void
    {
        $context = $vm?->context ?? $frame?->vmContext;
        if (null === $context) {
            return;
        }
        $context->errors->languageWarning(
            'Array to string conversion',
            null,
            0,
            $context,
            $frame
        );
    }

    private static function warnNonNumericValue(?\PHPCompiler\VM $vm, ?\PHPCompiler\Frame $frame): void
    {
        if (null === $vm) {
            return;
        }
        $vm->context->errors->triggerError(
            'A non-numeric value encountered',
            ErrorReporter::E_WARNING,
            '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
            $vm->context,
            $frame
        );
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

    /**
     * Zend convert_scalar_to_number for string operands in add_function / compound assign (#4892).
     *
     * @return array{0: int|float, 1: bool} numeric value and whether E_WARNING is needed
     */
    private static function parseStringForArithmetic(string $s): array
    {
        if (is_numeric($s)) {
            if (((string) (int) $s) === $s) {
                return [(int) $s, false];
            }

            return [(float) $s, false];
        }
        if (!preg_match('/^\s*[+-]?(?:(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $s, $m)) {
            throw new \LogicException('no_numeric_prefix');
        }
        $matched = $m[0];
        if ('' === ltrim($matched) || !preg_match('/\d/', $matched)) {
            throw new \LogicException('no_numeric_prefix');
        }
        $numPart = ltrim($matched, " \t\n\r\0\x0B");
        if (((string) (int) $numPart) === $numPart
            && !str_contains($numPart, '.')
            && !str_contains(strtolower($numPart), 'e')) {
            return [(int) $numPart, true];
        }

        return [(float) $numPart, true];
    }

    /**
     * Numeric coercion for binary/compound arithmetic (zend_operators.c add_function, #4892).
     */
    public function toNumericForArithmetic(
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): int|float {
        if (self::TYPE_INDIRECT === $this->type) {
            return $this->indirect->toNumericForArithmetic($vm, $frame);
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
                try {
                    [$value, $warn] = self::parseStringForArithmetic($this->string);
                } catch (\LogicException) {
                    throw new \TypeError(sprintf(
                        'Unsupported operand types: %s',
                        self::operandZendTypeName($this)
                    ));
                }
                if ($warn) {
                    self::warnNonNumericValue($vm, $frame);
                }

                return $value;
            case self::TYPE_OBJECT:
                if (self::isEnumCaseOperand($this)) {
                    throw new \TypeError(sprintf(
                        'Unsupported operand types: %s',
                        self::operandZendTypeName($this)
                    ));
                }

                return self::toNumericForArithmeticFromVariable(
                    $this->objectToScalarString($vm, 'int'),
                    $vm,
                    $frame
                );
            case self::TYPE_ENUM_CASE:
                throw new \TypeError(sprintf(
                    'Unsupported operand types: %s',
                    self::operandZendTypeName($this)
                ));
        }
        throw new \TypeError(sprintf(
            'Unsupported operand types: %s',
            self::operandZendTypeName($this)
        ));
    }

    private static function toNumericForArithmeticFromVariable(
        Variable $var,
        ?\PHPCompiler\VM $vm,
        ?\PHPCompiler\Frame $frame
    ): int|float {
        return $var->toNumericForArithmetic($vm, $frame);
    }

    /**
     * Int↔string loose == prefers exact integer numeric strings; other numeric strings (e.g. '0e5')
     * fall back to {@see looseNumericFromString} (#4035, Zend zend_operators.c).
     *
     * Non-numeric strings do not coerce to 0 (PHP 8.2+, #5178).
     */
    private static function looseIntegerFromString(string $s): ?int
    {
        if (!is_numeric($s)) {
            return null;
        }
        if (((string) (int) $s) === $s) {
            return (int) $s;
        }

        return null;
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
        // Zend: enum case loose == with backing scalar is false (#5798, #5819/#5835 switch labels).
        if (self::isEnumCaseOperand($self) || self::isEnumCaseOperand($other)) {
            if (self::isEnumCaseOperand($self) && self::isEnumCaseOperand($other)) {
                return EnumCaseSupport::enumCaseVariablesEqual($self, $other);
            }

            return false;
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
            $parsed = self::looseIntegerFromString($self->string);
            if (null !== $parsed) {
                return $other->integer == $parsed;
            }
            if (is_numeric($self->string)) {
                return $other->integer == self::looseNumericFromString($self->string);
            }

            return false;
        }
        if ($self->type === self::TYPE_INTEGER && $other->type === self::TYPE_STRING) {
            if ('' === $other->string) {
                return false;
            }
            $parsed = self::looseIntegerFromString($other->string);
            if (null !== $parsed) {
                return $self->integer == $parsed;
            }
            if (is_numeric($other->string)) {
                return $self->integer == self::looseNumericFromString($other->string);
            }

            return false;
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
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        // Zend compare_function: relational < > <= >= with enum cases are always false (#5812).
        if (self::isEnumCaseOperand($left) || self::isEnumCaseOperand($right)) {
            switch ($opCode) {
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                    $this->reset();
                    $this->bool(false);

                    return;
            }
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
            case TYPE_PAIR_ARRAY_ARRAY:
                $this->bool($this->_compareFromSpaceship(
                    $opCode,
                    $left->array->compareSpaceship($right->array)
                ));
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
        if ((\is_float($left) && \is_nan($left)) || (\is_float($right) && \is_nan($right))) {
            switch ($opCode) {
                case OpCode::TYPE_IDENTICAL:
                    return $left === $right;
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                    // Zend compare_function: relational ops with NaN are always false (#4712).
                    return false;
            }
        }
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

    /** Zend compare_function relational ops from zend_compare_arrays spaceship result (#5295). */
    private function _compareFromSpaceship(int $opCode, int $cmp): bool
    {
        switch ($opCode) {
            case OpCode::TYPE_GREATER:
                return $cmp > 0;
            case OpCode::TYPE_SMALLER:
                return $cmp < 0;
            case OpCode::TYPE_GREATER_OR_EQUAL:
                return $cmp >= 0;
            case OpCode::TYPE_SMALLER_OR_EQUAL:
                return $cmp <= 0;
            default:
                throw new \LogicException("Non-implemented array comparison operation $opCode");
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
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        $leftCopy = new self();
        $leftCopy->copyFrom($left);
        $rightCopy = new self();
        $rightCopy->copyFrom($right);
        $this->reset();
restart:
        switch (type_pair($leftCopy->type, $rightCopy->type)) {
            case TYPE_PAIR_INTEGER_INTEGER:
                $this->int($this->_spaceship($leftCopy->integer, $rightCopy->integer));
                break;
            case TYPE_PAIR_INTEGER_FLOAT:
                $this->int($this->_spaceship($leftCopy->integer, $rightCopy->float));
                break;
            case TYPE_PAIR_FLOAT_INTEGER:
                $this->int($this->_spaceship($leftCopy->float, $rightCopy->integer));
                break;
            case TYPE_PAIR_FLOAT_FLOAT:
                $this->int($this->_spaceship($leftCopy->float, $rightCopy->float));
                break;
            case TYPE_PAIR_STRING_STRING:
                $cmp = strcmp($leftCopy->string, $rightCopy->string);
                $this->int($cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0));
                break;
            case TYPE_PAIR_BOOLEAN_BOOLEAN:
                $this->int($this->_spaceship((int) $leftCopy->bool, (int) $rightCopy->bool));
                break;
            case TYPE_PAIR_NULL_NULL:
                $this->int(0);
                break;
            case TYPE_PAIR_OBJECT_OBJECT:
                $this->int($leftCopy->object->compareSpaceship($rightCopy->object));
                break;
            case TYPE_PAIR_ENUM_CASE_ENUM_CASE:
                $this->int(EnumCaseSupport::compareEnumCaseEntrySpaceship(
                    $leftCopy->toEnumCase(),
                    $rightCopy->toEnumCase()
                ));
                break;
            case TYPE_PAIR_ARRAY_ARRAY:
                $this->int($leftCopy->array->compareSpaceship($rightCopy->array));
                break;
            default:
                if ($leftCopy->type === self::TYPE_INDIRECT) {
                    $leftCopy = $leftCopy->indirect;
                    goto restart;
                } elseif ($rightCopy->type === self::TYPE_INDIRECT) {
                    $rightCopy = $rightCopy->indirect;
                    goto restart;
                } elseif (self::isEnumCaseOperand($leftCopy) || self::isEnumCaseOperand($rightCopy)) {
                    if (self::isEnumCaseOperand($leftCopy) && self::isEnumCaseOperand($rightCopy)) {
                        if (self::TYPE_ENUM_CASE === $leftCopy->type && self::TYPE_ENUM_CASE === $rightCopy->type) {
                            $this->int(EnumCaseSupport::compareEnumCaseEntrySpaceship(
                                $leftCopy->toEnumCase(),
                                $rightCopy->toEnumCase()
                            ));
                        } else {
                            $leftObj = self::TYPE_ENUM_CASE === $leftCopy->type
                                ? EnumCaseSupport::receiverForInstanceMethod($leftCopy)->toObject()
                                : $leftCopy->toObject();
                            $rightObj = self::TYPE_ENUM_CASE === $rightCopy->type
                                ? EnumCaseSupport::receiverForInstanceMethod($rightCopy)->toObject()
                                : $rightCopy->toObject();
                            $this->int(EnumCaseSupport::compareSpaceship($leftObj, $rightObj));
                        }
                    } else {
                        // Zend compare_function: enum case vs non-case is always 1 (#4554).
                        $this->int(1);
                    }
                } else {
                    $this->int(self::spaceshipMixedScalars($leftCopy, $rightCopy));
                }
        }
    }

    /**
     * Zend compare_function / spaceship for unlike scalar types (zend_operators.c, #4681).
     */
    private static function spaceshipMixedScalars(Variable $left, Variable $right): int
    {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();

        if (self::TYPE_BOOLEAN === $left->type && self::TYPE_STRING === $right->type) {
            return self::spaceshipValues((int) $left->bool, (int) $right->toBool());
        }
        if (self::TYPE_STRING === $left->type && self::TYPE_BOOLEAN === $right->type) {
            return self::spaceshipValues((int) $left->toBool(), (int) $right->bool);
        }

        if (self::TYPE_NULL === $left->type && self::TYPE_STRING === $right->type) {
            if ('' === $right->string) {
                return 0;
            }

            return self::spaceshipNumberString(0, $right->string, true);
        }
        if (self::TYPE_STRING === $left->type && self::TYPE_NULL === $right->type) {
            if ('' === $left->string) {
                return 0;
            }

            return -self::spaceshipNumberString(0, $left->string, true);
        }

        if (self::TYPE_INTEGER === $left->type && self::TYPE_STRING === $right->type) {
            return self::spaceshipNumberString($left->integer, $right->string, true);
        }
        if (self::TYPE_STRING === $left->type && self::TYPE_INTEGER === $right->type) {
            return -self::spaceshipNumberString($right->integer, $left->string, true);
        }
        if (self::TYPE_FLOAT === $left->type && self::TYPE_STRING === $right->type) {
            return self::spaceshipNumberString($left->float, $right->string, true);
        }
        if (self::TYPE_STRING === $left->type && self::TYPE_FLOAT === $right->type) {
            return -self::spaceshipNumberString($right->float, $left->string, true);
        }

        if (self::TYPE_BOOLEAN === $left->type
            && (self::TYPE_INTEGER === $right->type || self::TYPE_FLOAT === $right->type || self::TYPE_NULL === $right->type)
        ) {
            $rightNum = self::TYPE_NULL === $right->type ? 0 : $right->toNumeric();

            return self::spaceshipValues((int) $left->bool, $rightNum);
        }
        if (self::TYPE_BOOLEAN === $right->type
            && (self::TYPE_INTEGER === $left->type || self::TYPE_FLOAT === $left->type || self::TYPE_NULL === $left->type)
        ) {
            $leftNum = self::TYPE_NULL === $left->type ? 0 : $left->toNumeric();

            return self::spaceshipValues($leftNum, (int) $right->bool);
        }

        if (self::TYPE_NULL === $left->type
            && (self::TYPE_INTEGER === $right->type || self::TYPE_FLOAT === $right->type)
        ) {
            return self::spaceshipValues(0, $right->toNumeric());
        }
        if (self::TYPE_NULL === $right->type
            && (self::TYPE_INTEGER === $left->type || self::TYPE_FLOAT === $left->type)
        ) {
            return self::spaceshipValues($left->toNumeric(), 0);
        }

        if (self::TYPE_INTEGER === $left->type && self::TYPE_FLOAT === $right->type) {
            return self::spaceshipValues($left->integer, $right->float);
        }
        if (self::TYPE_FLOAT === $left->type && self::TYPE_INTEGER === $right->type) {
            return self::spaceshipValues($left->float, $right->integer);
        }

        return self::spaceshipValues($left->toNumeric(), $right->toNumeric());
    }

    /** @param int|float $num */
    private static function spaceshipNumberString(int|float $num, string $str, bool $numOnLeft): int
    {
        if ('' === $str) {
            return $numOnLeft ? 1 : -1;
        }
        if (is_numeric($str)) {
            $cmp = self::spaceshipValues($num, self::looseNumericFromString($str));

            return $numOnLeft ? $cmp : -$cmp;
        }

        return $numOnLeft ? -1 : 1;
    }

    /** @param int|float $left */
    private static function spaceshipValues(int|float $left, int|float $right): int
    {
        return self::spaceshipNumeric($left, $right);
    }

    /** Zend compare_function / spaceship for numeric operands (#4712). */
    private static function spaceshipNumeric(int|float $left, int|float $right): int
    {
        if ((\is_float($left) && \is_nan($left)) || (\is_float($right) && \is_nan($right))) {
            return 1;
        }
        if ($left < $right) {
            return -1;
        }
        if ($left > $right) {
            return 1;
        }

        return 0;
    }

    private static function isEnumCaseOperand(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (self::TYPE_ENUM_CASE === $var->type) {
            return true;
        }

        return self::TYPE_OBJECT === $var->type && EnumCaseSupport::isEnumCase($var->object);
    }

    private function _spaceship($left, $right): int {
        return self::spaceshipNumeric($left, $right);
    }

    public function bitwiseOp(
        int $opCode,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->bitwiseOp($opCode, $left, $right, $vm, $frame);
            $this->indirect->copyFrom($result);

            return;
        }
        $this->reset();
restart:
        if ($left->type === self::TYPE_INDIRECT) {
            $left = $left->indirect;
            goto restart;
        }
        if ($right->type === self::TYPE_INDIRECT) {
            $right = $right->indirect;
            goto restart;
        }
        if (OpCode::TYPE_SHIFT_LEFT === $opCode || OpCode::TYPE_SHIFT_RIGHT === $opCode) {
            if (!self::operandsValidForBitwiseOp($left, $right)) {
                self::throwUnsupportedOperandTypes($opCode, $left, $right);
            }
            $this->int($this->_bitwiseOp($opCode, $left->toNumeric(), $right->toNumeric()));

            return;
        }
        if (!self::operandsValidForBitwiseOp($left, $right)) {
            self::throwUnsupportedOperandTypes($opCode, $left, $right);
        }
        if ($this === $left || $this === $right) {
            $this->storeBitwiseOp(
                $opCode,
                $left,
                $right,
                $vm,
                $frame
            );

            return;
        }
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
        } elseif ($pair === TYPE_PAIR_STRING_STRING) {
            $this->string($this->_bitwiseOp($opCode, $left->toString(), $right->toString()));
        } else {
            $this->storeBitwiseOp($opCode, $left, $right, $vm, $frame);
        }
    }

    /**
     * Zend bitwise ops: string-by-string only when both operands are strings (#5428).
     */
    private function storeBitwiseOp(
        int $opCode,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if (self::TYPE_STRING === $left->type && self::TYPE_STRING === $right->type) {
            $this->string($this->_bitwiseOp($opCode, $left->toString(), $right->toString()));

            return;
        }
        $result = $this->_bitwiseOp(
            $opCode,
            $left->toNumericForArithmetic($vm, $frame),
            $right->toNumericForArithmetic($vm, $frame)
        );
        if (is_int($result)) {
            $this->int($result);
        } else {
            $this->float($result);
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

    public function numericOp(
        int $opCode,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->numericOp($opCode, $left, $right, $vm, $frame);
            $this->indirect->copyFrom($result);

            return;
        }
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        TypedPropertyCheck::assertReadable($left);
        TypedPropertyCheck::assertReadable($right);
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
            $this->storeNumericOp(
                $opCode,
                self::numericForArithmeticOp($opCode, $left, $left, $right, $vm, $frame),
                self::numericForArithmeticOp($opCode, $right, $left, $right, $vm, $frame)
            );

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
        } elseif (OpCode::TYPE_MODULO === $opCode
            && ($pair === TYPE_PAIR_INTEGER_FLOAT
                || $pair === TYPE_PAIR_FLOAT_INTEGER
                || $pair === TYPE_PAIR_FLOAT_FLOAT)) {
            $leftNum = TYPE_PAIR_INTEGER_FLOAT === $pair ? $left->integer : $left->float;
            $rightNum = TYPE_PAIR_FLOAT_INTEGER === $pair ? $right->integer : $right->float;
            $this->int($this->_numericOp($opCode, $leftNum, $rightNum));
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
            $result = $this->_numericOp(
                $opCode,
                self::numericForArithmeticOp($opCode, $left, $left, $right, $vm, $frame),
                self::numericForArithmeticOp($opCode, $right, $left, $right, $vm, $frame)
            );
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
        if (self::TYPE_STRING_OFFSET === $this->type) {
            throw new \Error(self::STRING_OFFSET_INCDEC_ERROR);
        }
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        if (self::TYPE_BOOLEAN === $left->type) {
            $this->copyFrom($left);
            if (OpCode::TYPE_PLUS === $opCode) {
                $this->applyIncrement();
            } else {
                $this->applyDecrement();
            }

            return;
        }
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
        if ('' === $str) {
            $this->int(-1);

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

    /**
     * Zend mod_function(): float operands truncate toward zero to zend_long before %.
     *
     * @see php-src Zend/zend_operators.c mod_function()
     */
    private static function numericToZendLong(int|float $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value >= 0.0) {
            return (int) floor($value);
        }

        return (int) ceil($value);
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
                if (0 === $right || 0.0 === $right) {
                    throw new \DivisionByZeroError('Division by zero');
                }

                return $left / $right;
            case OpCode::TYPE_MODULO:
                $rightLong = self::numericToZendLong($right);
                if (0 === $rightLong) {
                    throw new \DivisionByZeroError('Modulo by zero');
                }

                return self::numericToZendLong($left) % $rightLong;
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
                // PHP 8.2+ zend_operators.c: bool inc/dec is a no-op (issue #7058, re-#4727).
                return;
            case self::TYPE_UNDEFINED:
            case self::TYPE_NULL:
                // Zend increment_function(): IS_NULL → int 0 then ++ (issue #7435).
                $this->int(1);

                return;
            case self::TYPE_INTEGER:
                if ($this->isVmResource()) {
                    throw new \TypeError('Cannot increment resource');
                }
                ++$this->integer;

                return;
            case self::TYPE_FLOAT:
                $this->float += 1;

                return;
            case self::TYPE_STRING_OFFSET:
                throw new \Error(self::STRING_OFFSET_INCDEC_ERROR);
            case self::TYPE_STRING:
                $this->applyStringIncDec(OpCode::TYPE_PLUS, $this->string);

                return;
            case self::TYPE_ARRAY:
                throw new \TypeError('Cannot increment array');
            default:
                if (self::isEnumCaseOperand($this)) {
                    throw new \TypeError(
                        'Cannot increment '.self::operandEnumClassName($this)
                    );
                }
                if (self::TYPE_OBJECT === $this->type) {
                    if (ResourceSupport::isResourceObject($this->object)) {
                        throw new \TypeError('Cannot increment resource');
                    }
                    throw new \TypeError(
                        'Cannot increment '.$this->object->class->name
                    );
                }
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
                // PHP 8.2+ zend_operators.c: bool inc/dec is a no-op (issue #7058, re-#4727).
                return;
            case self::TYPE_UNDEFINED:
            case self::TYPE_NULL:
                // Zend decrement_function(): IS_NULL is a no-op on PHP 8.x (issue #7435).
                return;
            case self::TYPE_INTEGER:
                if ($this->isVmResource()) {
                    throw new \TypeError('Cannot decrement resource');
                }
                --$this->integer;

                return;
            case self::TYPE_FLOAT:
                $this->float -= 1;

                return;
            case self::TYPE_STRING_OFFSET:
                throw new \Error(self::STRING_OFFSET_INCDEC_ERROR);
            case self::TYPE_STRING:
                $this->applyStringIncDec(OpCode::TYPE_MINUS, $this->string);

                return;
            case self::TYPE_ARRAY:
                throw new \TypeError('Cannot decrement array');
            default:
                if (self::isEnumCaseOperand($this)) {
                    throw new \TypeError(
                        'Cannot decrement '.self::operandEnumClassName($this)
                    );
                }
                if (self::TYPE_OBJECT === $this->type) {
                    if (ResourceSupport::isResourceObject($this->object)) {
                        throw new \TypeError('Cannot decrement resource');
                    }
                    throw new \TypeError(
                        'Cannot decrement '.$this->object->class->name
                    );
                }
                $one = new self();
                $one->int(1);
                $this->numericOp(OpCode::TYPE_MINUS, $this, $one);
        }
    }

    public function unaryOp(int $opCode, Variable $expr, ?\PHPCompiler\VM $vm = null, ?\PHPCompiler\Frame $frame = null): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->unaryOp($opCode, $expr, $vm, $frame);
            $this->indirect->copyFrom($result);

            return;
        }
        $this->reset();
restart:
        switch ($opCode) {
            case OpCode::TYPE_UNARY_PLUS:
                $number = self::coerceUnaryPlusOperand($expr, $vm, $frame);
                if (is_int($number)) {
                    $this->int($number);
                } else {
                    $this->float($number);
                }

                return;
            case OpCode::TYPE_UNARY_MINUS:
                $resolved = $expr->resolveIndirect();
                if (self::isEnumCaseOperand($resolved)) {
                    throw new \TypeError(sprintf(
                        'Unsupported operand types: %s * int',
                        self::operandZendTypeName($resolved)
                    ));
                }
                if ($resolved->type === Variable::TYPE_INTEGER) {
                    $this->copyFrom($resolved);
                    $this->integer *= -1;
                    return;
                }
                if ($resolved->type === Variable::TYPE_FLOAT) {
                    $this->copyFrom($resolved);
                    $this->float *= -1.0;

                    return;
                }
                $number = self::coerceUnaryPlusOperand($resolved, $vm, $frame);
                if (is_int($number)) {
                    $this->int(-$number);
                } else {
                    $this->float(-$number);
                }

                return;
            case OpCode::TYPE_BITWISE_NOT:
                $resolved = $expr->resolveIndirect();
                if (self::isEnumCaseOperand($resolved)) {
                    throw new \TypeError(sprintf(
                        'Cannot perform bitwise not on %s',
                        self::operandEnumClassName($resolved)
                    ));
                }
                if ($resolved->type === self::TYPE_INTEGER) {
                    $this->int(~$resolved->integer);

                    return;
                }
                if ($resolved->type === self::TYPE_FLOAT) {
                    $this->int(~(int) $resolved->float);

                    return;
                }
                if ($resolved->type === self::TYPE_STRING) {
                    $bytes = $resolved->string;
                    $out = '';
                    for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
                        $out .= chr((~ord($bytes[$i])) & 0xFF);
                    }
                    $this->string($out);

                    return;
                }
                if ($resolved->type === self::TYPE_BOOLEAN || $resolved->type === self::TYPE_NULL) {
                    throw new \TypeError(sprintf(
                        'Cannot perform bitwise not on %s',
                        self::TYPE_BOOLEAN === $resolved->type ? 'bool' : 'null'
                    ));
                }
                $this->castFrom(self::CAST_NUMERIC, $resolved);
                goto restart;
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
                    $this->stringOffsetContext,
                    $this->stringOffsetFrame,
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
        $index = $rawIndex;
        if ($index < 0) {
            $index += $len;
        }
        if ($index < 0) {
            if (null !== $this->stringOffsetReporter) {
                $this->stringOffsetReporter->illegalStringOffset(
                    $rawIndex,
                    $this->stringOffsetContext,
                    $this->stringOffsetFrame,
                    $this->stringOffsetFile
                );
            }

            return;
        }
        $byte = self::byteFromAssignValue($value);
        if ($index > $len) {
            $str .= str_repeat("\0", $index - $len);
        }
        if ($index >= $len) {
            if ($index === strlen($str)) {
                $str .= $byte;
            } else {
                $str[$index] = $byte;
            }
        } else {
            $str[$index] = $byte;
        }
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
const TYPE_PAIR_ENUM_CASE_ENUM_CASE = 2313;
const TYPE_PAIR_BOOLEAN_BOOLEAN = 771;
const TYPE_PAIR_NULL_NULL = 0;
const TYPE_PAIR_ARRAY_ARRAY = 1542;

function type_pair(int $left, int $right): int {
    return $left * 256 + $right;
}
