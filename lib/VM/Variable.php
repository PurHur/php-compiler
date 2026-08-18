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
use PHPCompiler\ext\standard\VmScalarType;
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
    /** @see Zend/zend_execute.c zend_binary_assign_op_* — assign-op on string offsets (#22897) */
    public const STRING_OFFSET_ASSIGN_OP_ERROR = 'Cannot use assign-op operators with string offsets';
    /** @see Zend/zend_execute.c zend_assign_to_string_offset() — empty/null RHS */
    public const STRING_OFFSET_EMPTY_ASSIGN_ERROR = 'Cannot assign an empty string to a string offset';
    /** @see Zend/zend_execute.c zend_assign_to_string_offset() — multi-byte RHS (#22380) */
    public const STRING_OFFSET_FIRST_BYTE_WARNING = 'Only the first byte will be assigned to the string offset';
    /** @see Zend/zend_execute.c zend_fetch_dimension_address() — BP_VAR_W/RW string dim by-ref (#21910) */
    public const STRING_OFFSET_REF_ERROR = 'Cannot create references to/from string offsets';
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

    /**
     * True for Context / ClosureState function-local static storage cells (#28039).
     * Frame teardown must not releaseRef() objects through INDIRECT aliases into these cells.
     */
    public bool $functionStaticStorage = false;

    /**
     * True for HashTable buckets inside class-static / function-static / global / instance-property
     * arrays (#31937). FETCH_DIM_W leaves an INDIRECT into the bucket; releasing that alias on
     * frame exit drops the object's refcount while the persistent table still holds the pointer,
     * so destroyForGc wipes properties (singleton / registry pattern).
     */
    public bool $persistentHashTableBucket = false;

    /**
     * True when this INDIRECT alias was created by ASSIGN_REF to a typed property
     * (`$r = &$obj->prop` / `&Class::$prop`). TypeError messages then use Zend's
     * "reference held by property" wording (#25622, zend_execute.c).
     */
    public bool $typedPropertyByRef = false;

    /**
     * True when this INDIRECT is a PROPERTY_FETCH_WRITE / static write lvalue temp
     * (direct `$obj->prop =` / `Class::$prop =`). Opposite of {@see $typedPropertyByRef}.
     */
    public bool $propertyAssignLvalue = false;

    /**
     * array_walk / array_walk_recursive by-ref alias into object property HT storage.
     * Writes mutate the backing cell without invoking set hooks (php-src php_array_walk /
     * zend_property_hooks.c, #29703). Must not be set on the permanent property slot.
     */
    public bool $skipPropertySetHook = false;

    /**
     * True when this INDIRECT was produced by PROPERTY_FETCH_WRITE (or static fetch) used as a
     * reference-acquisition temp (`$r = &$obj->prop` / by-ref return fetch). ASSIGN_REF re-checks
     * visibility only for these temps — not for already-acquired by-ref call returns (#29456).
     */
    public bool $propertyRefAcquisition = false;

    /**
     * Zend ZSTR_IS_INTERNED — compile-time string literals / interned table entries (#22716).
     * Used by debug_zval_dump(); cleared on fresh {@see string()} allocations.
     */
    public bool $stringInterned = false;

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

    /** GeneratorState current key/value — must not be releaseVmDeadScopeSlot temps (#18184). */
    public bool $generatorYieldStorage = false;

    /** NamedArgs::assignVariadicArray() overflow pack — typed recv unpacks elements (#18647). */
    public bool $namedVariadicPack = false;

    /** Lvalue proxy for __set dispatch when the property slot does not exist (#146). */
    public ?ObjectEntry $magicSetTarget = null;

    public ?string $magicSetName = null;

    /** Lvalue proxy for ArrayObject::ARRAY_AS_PROPS property writes (#11893). */
    public ?ObjectEntry $arrayAsPropsTarget = null;

    public ?string $arrayAsPropsName = null;

    /**
     * Temporary from __get; non-object []= / dim-write must throw (#4673).
     * Objects (SimpleXMLElement / ArrayAccess) may accept write_dimension (#20005).
     */
    public ?ObjectEntry $magicGetOverloadedTarget = null;

    public ?string $magicGetOverloadedName = null;

    /** Hooked property dim modify: flush set hook after assign through this container (#6775). */
    public bool $propertyHookDimWriteBackPending = false;

    /** Dim lvalue → hooked property container pending set-hook writeback (#6775). */
    public ?Variable $hookedPropertyDimWriteBackContainer = null;

    /**
     * True when this Variable is a HashTable bucket cell (not a shared IS_REFERENCE payload).
     * ASSIGN_REF to a dim must promote in-place values to a shared ref cell so HT destroy
     * does not wipe aliases (#22027; Zend zend_fetch_dimension_address / IS_REFERENCE).
     */
    public bool $hashTableBucketCell = false;

    /**
     * Number of TYPE_INDIRECT wrappers pointing at this shared IS_REFERENCE cell
     * (Zend zend_reference.gc.refcount). Foreach-by-ref / ASSIGN_REF payloads only (#31936).
     */
    public int $sharedRefAliasCount = 0;

    /**
     * HashTable bucket whose TYPE_INDIRECT stores this cell. When the last named alias
     * is released and only the bucket remains, unwrap in place (zend_variables.c #31936).
     */
    public ?Variable $sharedRefBucket = null;

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

    /** ZEND_SEND_REF wrapper — inner lvalue for by-ref builtin writeback (#15151). */
    public function byRefTarget(): self
    {
        return $this->isIndirect() ? $this->indirect : $this;
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
                // SimpleXMLElement: sxe_object_cast_ex(IS_LONG) via element text (#22715).
                $sxeInt = \PHPCompiler\ext\simplexml\VmSimpleXml::tryCastObjectToInt($this->toObject());
                if (null !== $sxeInt) {
                    return $sxeInt;
                }

                // Zend convert_to_long object branch — legacy 1, no __toString (#18444, zend_operators.c).
                return 1;
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
                if (ResourceSupport::isResourceObject($this->toObject())) {
                    $handle = ResourceSupport::resolveHandle($this);
                    if (null !== $handle) {
                        return (float) $handle;
                    }
                }
                if (EnumCaseSupport::isEnumCase($this->toObject())) {
                    $enumFloat = EnumCaseSupport::tryCastToFloat($this, $vm?->context);
                    if (null !== $enumFloat) {
                        return $enumFloat;
                    }
                }
                // SimpleXMLElement: sxe_object_cast_ex(IS_DOUBLE) via element text (#22715).
                $sxeFloat = \PHPCompiler\ext\simplexml\VmSimpleXml::tryCastObjectToFloat($this->toObject());
                if (null !== $sxeFloat) {
                    return $sxeFloat;
                }

                // Zend convert_to_double object branch — legacy 1.0, no __toString (#18444, zend_operators.c).
                return 1.0;
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
                if (self::isIntegralNumericString($this->string)) {
                    return (int) $this->string;
                }
                return (float) $this->string;
            case self::TYPE_OBJECT:
                self::throwObjectNumericCompareError($this);
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
     * Zend shift_left/right_function — reject before numeric coerce (#30138, zend_operators.c).
     */
    public static function validateShiftOperands(
        int $opCode,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        self::toNumericForShift($left, $opCode, $left, $right, $vm, $frame);
        self::toNumericForShift($right, $opCode, $left, $right, $vm, $frame);
    }

    /**
     * @return int|float
     */
    private static function toNumericForShift(
        Variable $operand,
        int $opCode,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): int|float {
        $operand = $operand->resolveIndirect();
        TypedPropertyCheck::assertReadable($operand);
        switch ($operand->type) {
            case self::TYPE_NULL:
                return 0;
            case self::TYPE_INTEGER:
                return $operand->integer;
            case self::TYPE_FLOAT:
                return $operand->float;
            case self::TYPE_BOOLEAN:
                return $operand->bool ? 1 : 0;
            case self::TYPE_STRING:
                if (!is_numeric($operand->string)) {
                    self::throwUnsupportedOperandTypes(
                        $opCode,
                        $left->resolveIndirect(),
                        $right->resolveIndirect()
                    );
                }
                if (self::isIntegralNumericString($operand->string)) {
                    return (int) $operand->string;
                }

                return (float) $operand->string;
            case self::TYPE_OBJECT:
            case self::TYPE_ENUM_CASE:
            default:
                self::throwUnsupportedOperandTypes(
                    $opCode,
                    $left->resolveIndirect(),
                    $right->resolveIndirect()
                );
        }
    }

    /**
     * Zend rejects all assign-op operators on string offsets before operand evaluation (#22897).
     *
     * @see Zend/zend_execute.c zend_binary_assign_op_dim / string offset guard
     */
    public static function rejectAssignOpOnStringOffset(Variable ...$operands): void
    {
        foreach ($operands as $operand) {
            if (self::TYPE_STRING_OFFSET === $operand->resolveIndirect()->type) {
                throw new \Error(self::STRING_OFFSET_ASSIGN_OP_ERROR);
            }
        }
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

    /** Mark slot unbound (?? / coalesce left branch when property absent; #22649). */
    public function undefined(): void
    {
        $this->reset();
        $this->type = self::TYPE_UNDEFINED;
    }

    /** Clear a WeakReference target slot without dropping a strong ref (#13474, zend_weakrefs.c). */
    public function clearWeakTarget(): void
    {
        $this->releaseTrackedMemory();
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
                $object = $this->resolveIndirect()->toObject();
                // SimpleXMLElement: sxe_object_cast_ex(_IS_BOOL), not zend_std (always true) (#22714).
                if (\PHPCompiler\ext\simplexml\VmSimpleXml::handlesObjectCast($object)) {
                    return \PHPCompiler\ext\simplexml\VmSimpleXml::objectIsTruthy($object);
                }
                // zend_std_cast_object_to_type(_IS_BOOL) → 1; __toString is not consulted (#26409).
                return true;
            case self::TYPE_ENUM_CASE:
                return true;
            case self::TYPE_PROPERTY_HOOK_REF:
                return $this->propertyHookRef->read()->toBool($vm);
        }
        throw new \LogicException("Cannot convert type {$this->type} to bool");
    }

    public function string(string $value, bool $interned = false): void {
        $this->reset();
        $this->type = self::TYPE_STRING;
        $this->string = $value;
        $this->stringInterned = $interned;
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
                return \PHPCompiler\ext\standard\VmZendDoubleString::format($var->float);
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
        if (self::TYPE_INDIRECT === $this->type && isset($this->indirect) && $this->indirect === $value) {
            return;
        }
        $this->reset();
        $this->typedPropertyByRef = false;
        $this->propertyAssignLvalue = false;
        $this->propertyRefAcquisition = false;
        $this->skipPropertySetHook = false;
        $this->type = self::TYPE_INDIRECT;
        $this->indirect = $value;
        $value->sharedRefAliasCount++;
    }

    public function directIndirectTarget(): ?self
    {
        if (self::TYPE_INDIRECT !== $this->type) {
            return null;
        }

        return $this->indirect;
    }

    /**
     * Drop this INDIRECT alias and unwrap a HashTable bucket that would otherwise remain
     * a sole zend_reference (var_dump `&` marker) — php-src zval_ptr_dtor / #31936.
     */
    private function releaseIndirectAlias(): void
    {
        if (self::TYPE_INDIRECT !== $this->type || !isset($this->indirect)) {
            return;
        }
        $cell = $this->indirect;
        if ($cell->sharedRefAliasCount > 0) {
            --$cell->sharedRefAliasCount;
        }
        if ($this->hashTableBucketCell) {
            return;
        }
        $this->tryCollapseSoleSharedRef($cell);
    }

    /**
     * When only the array bucket still holds the IS_REFERENCE cell, copy the payload
     * back onto the bucket so var_dump matches Zend (no `&` after unset($v)).
     */
    private function tryCollapseSoleSharedRef(self $cell): void
    {
        if (1 !== $cell->sharedRefAliasCount) {
            return;
        }
        $bucket = $cell->sharedRefBucket;
        if (null === $bucket || $bucket === $this) {
            return;
        }
        if (!$bucket->isIndirect() || $bucket->directIndirectTarget() !== $cell) {
            return;
        }
        $bucket->unwrapIndirectInPlace();
        $cell->sharedRefAliasCount = 0;
        $cell->sharedRefBucket = null;
        $cell->reset();
    }

    /**
     * Replace TYPE_INDIRECT with a value copy of the shared cell (no write-through).
     */
    private function unwrapIndirectInPlace(): void
    {
        if (self::TYPE_INDIRECT !== $this->type || !isset($this->indirect)) {
            return;
        }
        $cell = $this->indirect;
        $this->type = self::TYPE_NULL;
        unset($this->indirect);
        $this->copyFrom($cell);
    }

    public function reset(): void {
        $this->releaseIndirectAlias();
        $this->releaseTrackedMemory();
        if (self::TYPE_OBJECT === $this->type && isset($this->object)) {
            if ($this->object->refCount <= 1) {
                WeakRefRegistry::clearForObject($this->object->id);
            }
            ObjectLifetime::releaseRef($this->object);
        }
        $this->releaseArrayRef();
        $this->resetScalars();
        $this->type = self::TYPE_NULL;
        $this->stringInterned = false;
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

    /**
     * ZEND_ASSIGN_DIM_OP reads the live offsetGet payload, not the ArrayAccess view type (#31947).
     *
     * TYPE_ARRAYACCESS_OFFSET is named "mixed" in TypeErrors; assign-op must use the stored int/float.
     */
    private static function unwrapArrayAccessOperand(self $var): self
    {
        $var = $var->resolveIndirect();
        if ($var->isArrayAccessOffset()) {
            return $var->readArrayAccessOffsetValue();
        }

        return $var;
    }

    public function arrayAccessOffsetClassName(): string
    {
        if (self::TYPE_ARRAYACCESS_OFFSET !== $this->type) {
            throw new \LogicException('Not an ArrayAccess offset');
        }

        return $this->arrayAccessDimension->declaringClassName();
    }

    public function getArrayAccessDimension(): ArrayAccessDimension
    {
        if (self::TYPE_ARRAYACCESS_OFFSET !== $this->type) {
            throw new \LogicException('Not an ArrayAccess offset');
        }

        return $this->arrayAccessDimension;
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
     * Zend string offset index: null/bool/float emit "String offset cast occurred" then coerce.
     *
     * Non-integral string / object / array dims → TypeError (php-src zend_check_string_offset).
     * Leading-numeric strings with trailing junk warn then coerce (#22895).
     *
     * php-src: Zend/zend_execute.c — zend_check_string_offset / zend_illegal_string_offset
     * php-src: Zend/zend_operators.c — string offset index cast (#4166 float, #22896 null/bool)
     */
    public static function stringOffsetIndexFromDim(
        self $dim,
        ?ErrorReporter $reporter = null,
        ?Context $context = null,
        ?\PHPCompiler\Frame $frame = null,
        ?string $file = null
    ): int {
        $dim = $dim->resolveIndirect();
        if (
            self::TYPE_FLOAT === $dim->type
            || self::TYPE_NULL === $dim->type
            || self::TYPE_BOOLEAN === $dim->type
        ) {
            if (null !== $reporter) {
                $reporter->stringOffsetCastOccurred($context, $frame, $file);
            }
            if (self::TYPE_FLOAT === $dim->type) {
                return (int) $dim->float;
            }

            return $dim->toInt();
        }
        if (self::TYPE_STRING === $dim->type) {
            $parsed = self::tryParseStringOffsetLong($dim->string);
            if (null === $parsed) {
                throw new \TypeError(sprintf(
                    'Cannot access offset of type %s on string',
                    self::operandZendTypeName($dim)
                ));
            }
            if ($parsed[1] && null !== $reporter) {
                $reporter->illegalStringOffsetQuoted($dim->string, $context, $frame, $file);
            }

            return $parsed[0];
        }
        if (
            self::TYPE_OBJECT === $dim->type
            || self::TYPE_ARRAY === $dim->type
            || self::TYPE_ENUM_CASE === $dim->type
        ) {
            throw new \TypeError(sprintf(
                'Cannot access offset of type %s on string',
                self::operandZendTypeName($dim)
            ));
        }

        return $dim->toInt();
    }

    /**
     * isset()/empty() on string offsets: in-bounds true, OOB false, no uninitialized warning (#5307).
     *
     * Illegal dims (non-integral string / object / array) → false, no TypeError
     * ({@see zend_isset_dim_slow}, #22895).
     *
     * Float dims emit Zend Implicit-conversion E_DEPRECATED (not "String offset cast occurred");
     * null/bool coerce silently — read-path cast warnings stay on {@see stringOffsetIndexFromDim}
     * (#29557 float; #29558 null/bool isset/empty).
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
        $dim = $dim->resolveIndirect();
        if (
            self::TYPE_OBJECT === $dim->type
            || self::TYPE_ARRAY === $dim->type
            || self::TYPE_ENUM_CASE === $dim->type
        ) {
            return false;
        }
        if (self::TYPE_STRING === $dim->type) {
            // is_numeric_string(..., allow_errors=0) — trailing junk → not set
            if (!self::isIntegralNumericString($dim->string)) {
                return false;
            }
            $rawIndex = (int) trim($dim->string);
        } elseif (self::TYPE_FLOAT === $dim->type) {
            $rawIndex = self::stringOffsetIssetIndexFromFloat($dim->float, $context, $frame);
        } elseif (self::TYPE_NULL === $dim->type || self::TYPE_BOOLEAN === $dim->type) {
            // zend_isset_dim: null/bool → long with no string-offset cast warning (#29558).
            $rawIndex = $dim->toInt();
        } else {
            $rawIndex = self::stringOffsetIndexFromDim($dim, $reporter, $context, $frame, $file);
        }
        $len = strlen($container->string);
        $index = $rawIndex;
        if ($index < 0) {
            $index += $len;
        }

        return $index >= 0 && $index < $len;
    }

    /**
     * Float→int index for isset()/empty() on strings (#29557).
     *
     * php-src: Zend/zend_execute.c — zend_isset_dim; Zend/zend_operators.c — precision-loss deprecate.
     */
    public static function stringOffsetIssetIndexFromFloat(
        float $value,
        ?Context $context = null,
        ?\PHPCompiler\Frame $frame = null
    ): int {
        $ctx = $context;
        if (null === $ctx) {
            $vm = VmEngine::running();
            $ctx = $vm?->context;
        }
        if (null !== $ctx) {
            \PHPCompiler\ext\standard\VmMath::warnFloatToIntPrecisionLoss($value, $ctx, $frame);
        }

        return \PHPCompiler\ext\standard\VmMath::floatToZendLong($value);
    }

    /**
     * Zend is_numeric_string_ex(allow_errors=true) IS_LONG arm for string-offset dims (#22895).
     *
     * @return array{0: int, 1: bool}|null [offset, hasTrailingData] or null when not IS_LONG
     */
    public static function tryParseStringOffsetLong(string $s): ?array
    {
        $len = \strlen($s);
        $i = 0;
        while ($i < $len && \ctype_space($s[$i])) {
            $i++;
        }
        if ($i >= $len) {
            return null;
        }
        $neg = false;
        if ('+' === $s[$i] || '-' === $s[$i]) {
            $neg = '-' === $s[$i];
            $i++;
        }
        if ($i >= $len || !\ctype_digit($s[$i])) {
            return null;
        }
        $digitStart = $i;
        while ($i < $len && \ctype_digit($s[$i])) {
            $i++;
        }
        // Float form (. / exponent) → IS_DOUBLE → TypeError for string offsets
        if ($i < $len && ('.' === $s[$i] || 'e' === $s[$i] || 'E' === $s[$i])) {
            return null;
        }
        $digits = \substr($s, $digitStart, $i - $digitStart);
        $digitsTrim = \ltrim($digits, '0');
        if ('' === $digitsTrim) {
            $digitsTrim = '0';
        }
        $limit = $neg ? \substr((string) \PHP_INT_MIN, 1) : (string) \PHP_INT_MAX;
        $dlen = \strlen($digitsTrim);
        $limitLen = \strlen($limit);
        if ($dlen > $limitLen || ($dlen === $limitLen && $digitsTrim > $limit)) {
            return null;
        }
        $offset = (int) (($neg ? '-' : '') . $digits);
        $trailing = false;
        while ($i < $len && \ctype_space($s[$i])) {
            $i++;
        }
        if ($i < $len) {
            $trailing = true;
        }

        return [$offset, $trailing];
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
                if (null !== $frame) {
                    VmScalarType::writeCoercedInt($this, $var, $frame);
                    break;
                }
                $this->integer = $var->toInt($vm);
                break;
            case Variable::TYPE_FLOAT:
                if (null !== $frame) {
                    VmScalarType::writeCoercedFloat($this, $var, $frame);
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
        // Prototype stamped TYPE_STRING/INTEGER/… without a payload (e.g. DateInterval::$date_string
        // before createFromDateString) — treat like uninitialized for zend_objects_clone_obj (#22893).
        if ($this->cloneSourceMissingScalarPayload($var)) {
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

    /** True when type tag is set but the typed PHP property payload was never written. */
    private function cloneSourceMissingScalarPayload(self $var): bool
    {
        switch ($var->type) {
            case self::TYPE_STRING:
                return !isset($var->string);
            case self::TYPE_INTEGER:
                return !isset($var->integer);
            case self::TYPE_FLOAT:
                return !isset($var->float);
            case self::TYPE_BOOLEAN:
                return !isset($var->bool);
            default:
                return false;
        }
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
                $this->string($var->string, $var->stringInterned);
                break;
            case self::TYPE_STRING_OFFSET:
                $this->string($var->toString());
                break;
            case self::TYPE_ARRAYACCESS_OFFSET:
                $this->copyFrom($var->readArrayAccessOffsetValue());
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
            case self::TYPE_UNDEFINED:
                if ($var->hasDeclaredTypeConstraint()) {
                    $this->copyUninitializedStaticPropertySlot($var);
                    break;
                }
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
            // Stream-context (and other resource-like) arrays are Zend resources: pass-by-value
            // must share the handle table, not zend_array_dup (#26762).
            if ($var->array->isResourceLikeHandle()) {
                $this->copyFrom($var);

                return;
            }
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
        // Same HashTable → identical (self-ref `$a[0]=&$a` / unserialize R:; #22652).
        // Deep compare uses identical element semantics (zend_hash_compare identical; #23485).
        if (self::TYPE_ARRAY === $self->type) {
            if ($self->array === $other->array) {
                return true;
            }

            return $self->toArray()->compareIdentical($other->toArray());
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

    public function equals(Variable $other, ?\PHPCompiler\VM $vm = null): bool {
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
                // php-src bcmath_number_obj_handlers.compare / gmp handlers — == shares
                // compare with <=>, not property-string equality (#23602).
                $extCmp = self::tryRegisteredNumericObjectCompare($self, $other);
                if (null !== $extCmp) {
                    return 0 === $extCmp;
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
                return $this->looseEqual($self, $other, $vm);
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
                throw new \TypeError(sprintf(
                    'Unsupported operand types: %s',
                    self::operandZendTypeName($expr)
                ));
        }
        throw new \TypeError(sprintf(
            'Unsupported operand types: %s',
            self::operandZendTypeName($expr)
        ));
    }

    /**
     * Zend _convert_to_string() array branch (zend_operators.c, issue #5266).
     *
     * $fallbackContext covers string-offset assign where the offset slot holds Context
     * but not a live VM (#22925 / zend_assign_to_string_offset).
     */
    private static function emitArrayToStringWarning(
        ?\PHPCompiler\VM $vm,
        ?\PHPCompiler\Frame $frame,
        ?Context $fallbackContext = null
    ): void {
        $context = $vm?->context ?? $frame?->vmContext ?? $fallbackContext;
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
        if (self::isIntegralNumericString($s)) {
            return (int) $s;
        }

        return (float) $s;
    }

    /**
     * Zend _is_numeric_string_ex IS_LONG branch — leading zeros stay int when in long range (#22823).
     *
     * Decimal point or exponent → double. Out-of-range digit strings → double.
     * Canonical form is not required ("010" is int 10, not float).
     *
     * @see php-src Zend/zend_operators.c _is_numeric_string_ex
     */
    public static function isIntegralNumericString(string $s): bool
    {
        if (str_contains($s, '.') || false !== stripos($s, 'e')) {
            return false;
        }
        $t = trim($s);
        if ('' === $t || '+' === $t || '-' === $t) {
            return false;
        }
        $neg = false;
        $c0 = $t[0];
        if ('+' === $c0 || '-' === $c0) {
            $neg = '-' === $c0;
            $t = substr($t, 1);
        }
        if ('' === $t || !ctype_digit($t)) {
            return false;
        }
        $digits = ltrim($t, '0');
        if ('' === $digits) {
            return true;
        }
        $limit = $neg ? substr((string) \PHP_INT_MIN, 1) : (string) \PHP_INT_MAX;
        $len = strlen($digits);
        $limitLen = strlen($limit);
        if ($len !== $limitLen) {
            return $len < $limitLen;
        }

        return $digits <= $limit;
    }

    /**
     * Zend convert_scalar_to_number for string operands in add_function / compound assign (#4892).
     *
     * @return array{0: int|float, 1: bool} numeric value and whether E_WARNING is needed
     */
    private static function parseStringForArithmetic(string $s): array
    {
        if (is_numeric($s)) {
            if (self::isIntegralNumericString($s)) {
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
        if (self::isIntegralNumericString($numPart)) {
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
                throw new \TypeError(sprintf(
                    'Unsupported operand types: %s',
                    self::operandZendTypeName($this)
                ));
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

    private function looseEqual(Variable $self, Variable $other, ?\PHPCompiler\VM $vm = null): bool {
        $stringableEq = CompareStringableHelper::looseEqual($vm, $self, $other);
        if (null !== $stringableEq) {
            return $stringableEq;
        }
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
            return $this->looseEqual($other, $self, $vm);
        }
        // Zend: enum case loose == with backing scalar is false (#5798, #5819/#5835 switch labels).
        if (self::isEnumCaseOperand($self) || self::isEnumCaseOperand($other)) {
            if (self::isEnumCaseOperand($self) && self::isEnumCaseOperand($other)) {
                return EnumCaseSupport::enumCaseVariablesEqual($self, $other);
            }

            return false;
        }
        // BcMath\Number / GMP == int|string|Number — php-src compare handlers (#23602).
        $extCmp = self::tryRegisteredNumericObjectCompare($self, $other);
        if (null !== $extCmp) {
            return 0 === $extCmp;
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
        // Zend compare_function: plain object == number → Notice + legacy 1 (#29122).
        if (
            (self::TYPE_OBJECT === $self->type
                && (self::TYPE_INTEGER === $other->type || self::TYPE_FLOAT === $other->type))
            || (self::TYPE_OBJECT === $other->type
                && (self::TYPE_INTEGER === $self->type || self::TYPE_FLOAT === $self->type))
        ) {
            return 0 === CompareUnlikeHelper::zendUnlikeValueSpaceship($self, $other, $vm);
        }
        try {
            return $self->toNumeric() == $other->toNumeric();
        } catch (\LogicException|\TypeError) {
            return false;
        }
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

    /**
     * php-src object handlers.compare for BcMath\Number / GMP — shared by == and <=> (#23602).
     *
     * @return int|null -1/0/1 when a registered handler applies; null otherwise
     */
    private static function tryRegisteredNumericObjectCompare(Variable $left, Variable $right): ?int
    {
        $cmp = \PHPCompiler\ext\bcmath\VmBcMathNumber::tryCompare($left, $right);
        if (null === $cmp) {
            $cmp = \PHPCompiler\ext\gmp\VmGmpObject::tryCompare($left, $right);
        }

        return $cmp;
    }

    public function compareOp(int $opCode, Variable $left, Variable $right, ?\PHPCompiler\VM $vm = null): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->compareOp($opCode, $left, $right, $vm);
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
                // Zend compare_function string branch uses zendi_smart_strcmp (#22848).
                $this->bool($this->_compareFromSpaceship(
                    $opCode,
                    CompareJitHelperScalars::stringSpaceship($left->string, $right->string)
                ));
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
                // Zend compare_function → zend_compare_objects / zend_std_compare_objects
                // (property order). Same path as <=> (#3691); do not numeric-coerce (#25241).
                $bcCmp = self::tryRegisteredNumericObjectCompare($left, $right);
                if (null !== $bcCmp) {
                    $this->bool($this->_compareFromSpaceship($opCode, $bcCmp));
                    break;
                }
                $this->bool($this->_compareFromSpaceship(
                    $opCode,
                    $left->object->compareSpaceship($right->object)
                ));
                break;
            default:
                if ($left->type === self::TYPE_INDIRECT) {
                    $left = $left->indirect;
                    goto restart;
                } elseif ($right->type === self::TYPE_INDIRECT) {
                    $right = $right->indirect;
                    goto restart;
                }
                $bcCmp = \PHPCompiler\ext\bcmath\VmBcMathNumber::tryCompare($left, $right);
                if (null === $bcCmp) {
                    $bcCmp = \PHPCompiler\ext\gmp\VmGmpObject::tryCompare($left, $right);
                }
                if (null !== $bcCmp) {
                    $this->bool($this->_compareFromSpaceship($opCode, $bcCmp));
                } elseif (self::needsZendUnlikeKindCompare($left, $right)) {
                    $this->bool($this->_compareFromSpaceship(
                        $opCode,
                        CompareUnlikeHelper::zendUnlikeValueSpaceship($left, $right, $vm)
                    ));
                } else {
                    // Zend compare_function: unlike scalars use spaceship parity (#4681, #10243).
                    $this->bool($this->_compareFromSpaceship(
                        $opCode,
                        self::spaceshipMixedScalars($left, $right)
                    ));
                }
        }
    }

    /** Unlike-kind compare that must not route through toNumeric() (#12033, zend_compare). */
    private static function needsZendUnlikeKindCompare(Variable $left, Variable $right): bool
    {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        if ($left->type === $right->type) {
            return false;
        }
        foreach ([self::TYPE_ARRAY, self::TYPE_OBJECT] as $kind) {
            if ($left->type === $kind || $right->type === $kind) {
                return true;
            }
        }
        if ((self::TYPE_NULL === $left->type && self::TYPE_ARRAY === $right->type)
            || (self::TYPE_ARRAY === $left->type && self::TYPE_NULL === $right->type)) {
            return true;
        }

        return false;
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

    public function spaceshipOp(Variable $left, Variable $right, ?\PHPCompiler\VM $vm = null): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->spaceshipOp($left, $right, $vm);
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
                // Zend zendi_smart_strcmp — numeric strings as numbers (#22848).
                $this->int(CompareJitHelperScalars::stringSpaceship($leftCopy->string, $rightCopy->string));
                break;
            case TYPE_PAIR_BOOLEAN_BOOLEAN:
                $this->int($this->_spaceship((int) $leftCopy->bool, (int) $rightCopy->bool));
                break;
            case TYPE_PAIR_NULL_NULL:
                $this->int(0);
                break;
            case TYPE_PAIR_OBJECT_OBJECT:
                $bcCmp = \PHPCompiler\ext\bcmath\VmBcMathNumber::tryCompare($leftCopy, $rightCopy);
                if (null === $bcCmp) {
                    $bcCmp = \PHPCompiler\ext\gmp\VmGmpObject::tryCompare($leftCopy, $rightCopy);
                }
                if (null !== $bcCmp) {
                    $this->int($bcCmp);
                    break;
                }
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
                } elseif (self::needsZendUnlikeKindCompare($leftCopy, $rightCopy)) {
                    $bcCmp = \PHPCompiler\ext\bcmath\VmBcMathNumber::tryCompare($leftCopy, $rightCopy);
                    if (null === $bcCmp) {
                        $bcCmp = \PHPCompiler\ext\gmp\VmGmpObject::tryCompare($leftCopy, $rightCopy);
                    }
                    if (null !== $bcCmp) {
                        $this->int($bcCmp);
                    } else {
                        $this->int(CompareUnlikeHelper::zendUnlikeValueSpaceship($leftCopy, $rightCopy, $vm));
                    }
                } else {
                    $bcCmp = \PHPCompiler\ext\bcmath\VmBcMathNumber::tryCompare($leftCopy, $rightCopy);
                    if (null === $bcCmp) {
                        $bcCmp = \PHPCompiler\ext\gmp\VmGmpObject::tryCompare($leftCopy, $rightCopy);
                    }
                    if (null !== $bcCmp) {
                        $this->int($bcCmp);
                    } else {
                        $this->int(self::spaceshipMixedScalars($leftCopy, $rightCopy));
                    }
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

    /**
     * Zend bitwise/shift numeric operands coerce through float->long before the host operator runs.
     */
    private static function coerceBitwiseNumericOperand(
        $value,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): int {
        if (\is_float($value)) {
            $opCtx = $frame?->vmContext ?? $vm?->context;
            if (null !== $opCtx) {
                \PHPCompiler\ext\standard\VmMath::warnFloatToIntPrecisionLoss($value, $opCtx, $frame);
            }

            return \PHPCompiler\ext\standard\VmMath::floatToZendLong($value);
        }

        return (int) $value;
    }

    public function bitwiseOp(
        int $opCode,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        self::rejectAssignOpOnStringOffset($this, $left, $right);
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->bitwiseOp($opCode, $left, $right, $vm, $frame);
            $this->indirect->copyFrom($result);

            return;
        }
        if ($this->type === self::TYPE_ARRAYACCESS_OFFSET) {
            $result = new self();
            $result->bitwiseOp(
                $opCode,
                self::unwrapArrayAccessOperand($left),
                self::unwrapArrayAccessOperand($right),
                $vm,
                $frame
            );
            $this->copyFrom($result);

            return;
        }
        $left = self::unwrapArrayAccessOperand($left);
        $right = self::unwrapArrayAccessOperand($right);
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
        // GMP bitwise / shift overload — php-src ext/gmp/gmp.c gmp_do_operation (#21265).
        $gmpCtx = $frame?->vmContext ?? $vm?->context;
        if (null !== $gmpCtx
            && \PHPCompiler\ext\gmp\VmGmpObject::tryDoOperation($this, $opCode, $left, $right, $gmpCtx)) {
            return;
        }
        if (OpCode::TYPE_SHIFT_LEFT === $opCode || OpCode::TYPE_SHIFT_RIGHT === $opCode) {
            if (!self::operandsValidForBitwiseOp($left, $right)) {
                self::throwUnsupportedOperandTypes($opCode, $left, $right);
            }
            $this->int($this->_bitwiseOp(
                $opCode,
                self::coerceBitwiseNumericOperand(
                    self::toNumericForShift($left, $opCode, $left, $right, $vm, $frame),
                    $vm,
                    $frame
                ),
                self::coerceBitwiseNumericOperand(
                    self::toNumericForShift($right, $opCode, $left, $right, $vm, $frame),
                    $vm,
                    $frame
                )
            ));

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
            $this->int($this->_bitwiseOp(
                $opCode,
                $left->integer,
                self::coerceBitwiseNumericOperand($right->float, $vm, $frame)
            ));
        } elseif ($pair === TYPE_PAIR_FLOAT_INTEGER) {
            $this->int($this->_bitwiseOp(
                $opCode,
                self::coerceBitwiseNumericOperand($left->float, $vm, $frame),
                $right->integer
            ));
        } elseif ($pair === TYPE_PAIR_FLOAT_FLOAT) {
            $this->int($this->_bitwiseOp(
                $opCode,
                self::coerceBitwiseNumericOperand($left->float, $vm, $frame),
                self::coerceBitwiseNumericOperand($right->float, $vm, $frame)
            ));
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
        try {
            $result = $this->_bitwiseOp(
                $opCode,
                self::coerceBitwiseNumericOperand($left->toNumericForArithmetic($vm, $frame), $vm, $frame),
                self::coerceBitwiseNumericOperand($right->toNumericForArithmetic($vm, $frame), $vm, $frame)
            );
        } catch (\TypeError) {
            self::throwUnsupportedOperandTypes($opCode, $left, $right);
        }
        $this->int($result);
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
            case OpCode::TYPE_SHIFT_RIGHT:
                // Zend shift_left/right_function — catchable ArithmeticError (#21912).
                $shiftCount = (int) $right;
                if ($shiftCount < 0) {
                    throw new \ArithmeticError('Bit shift by negative number');
                }

                return OpCode::TYPE_SHIFT_LEFT === $opCode
                    ? (int) $left << $shiftCount
                    : (int) $left >> $shiftCount;
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
        self::rejectAssignOpOnStringOffset($this, $left, $right);
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->numericOp($opCode, $left, $right, $vm, $frame);
            $this->indirect->copyFrom($result);

            return;
        }
        if ($this->type === self::TYPE_ARRAYACCESS_OFFSET) {
            $result = new self();
            $result->numericOp(
                $opCode,
                self::unwrapArrayAccessOperand($left),
                self::unwrapArrayAccessOperand($right),
                $vm,
                $frame
            );
            $this->copyFrom($result);

            return;
        }
        $left = self::unwrapArrayAccessOperand($left);
        $right = self::unwrapArrayAccessOperand($right);
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
        // BcMath\Number / GMP do_operation — php-src ext/bcmath (#20648, #21266) + ext/gmp (#21265).
        // Prefer frame context, but fall back to $vm->context: CFG try/catch/merge frames from
        // Block::getFrame often leave vmContext null even though the VM still has a live Context.
        $opCtx = $frame?->vmContext ?? $vm?->context;
        if (null !== $opCtx
            && (\PHPCompiler\ext\bcmath\VmBcMathNumber::tryDoOperation($this, $opCode, $left, $right, $opCtx)
                || \PHPCompiler\ext\gmp\VmGmpObject::tryDoOperation($this, $opCode, $left, $right, $opCtx))) {
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
            $opCtx = $frame?->vmContext ?? $vm?->context;
            if (null !== $opCtx) {
                if (\is_float($leftNum)) {
                    \PHPCompiler\ext\standard\VmMath::warnFloatToIntPrecisionLoss($leftNum, $opCtx, $frame);
                }
                if (\is_float($rightNum)) {
                    \PHPCompiler\ext\standard\VmMath::warnFloatToIntPrecisionLoss($rightNum, $opCtx, $frame);
                }
            }
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
    public function incDecOp(
        int $opCode,
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if ($this->type === self::TYPE_INDIRECT) {
            $result = new self();
            $result->incDecOp($opCode, $left, $right, $vm, $frame);
            $this->indirect->copyFrom($result);

            return;
        }
        if ($this->type === self::TYPE_ARRAYACCESS_OFFSET) {
            $result = new self();
            $result->incDecOp(
                $opCode,
                self::unwrapArrayAccessOperand($left),
                self::unwrapArrayAccessOperand($right),
                $vm,
                $frame
            );
            $this->copyFrom($result);

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
                $this->applyIncrement($vm, $frame);
            } else {
                $this->applyDecrement($vm, $frame);
            }

            return;
        }
        $strVar = self::TYPE_STRING === $left->type ? $left : (self::TYPE_STRING === $right->type ? $right : null);
        if (null !== $strVar) {
            $this->applyStringIncDec($opCode, $strVar->toString(), $vm, $frame);

            return;
        }
        if ($this === $left || $this === $right) {
            $this->storeNumericOp($opCode, $left->toNumeric(), $right->toNumeric());

            return;
        }
        $this->numericOp($opCode, $left, $right);
    }

    /**
     * Zend increment_function() / decrement_function() string path (zend_operators.c).
     *
     * Non-numeric `--` is a no-op with E_DEPRECATED (#29088). Empty `--` coerces to -1 with
     * E_DEPRECATED (#29658). Non-alnum / empty `++` uses increment_string() with E_DEPRECATED
     * and no peri-mutate of non-alnum bytes (#29658, RFC saner-inc-dec-operators).
     */
    private function applyStringIncDec(
        int $opCode,
        string $str,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if (OpCode::TYPE_PLUS === $opCode) {
            if (self::isNumericStringForIncDec($str)) {
                $this->storeNumericStringIncDec($str, 1);

                return;
            }
            // php-src increment_string(): empty or non-ASCII-alnum → E_DEPRECATED (#29658).
            if ('' === $str || !VmString::onlyAsciiAlphanumeric($str)) {
                self::warnIncrementNonAlphanumericString($vm, $frame);
            }
            $this->string(VmString::incrementStringOperator($str));

            return;
        }
        if ('' === $str) {
            // php-src decrement_function(): empty string → E_DEPRECATED then int -1 (#29658).
            self::warnDecrementEmptyString($vm, $frame);
            $this->int(-1);

            return;
        }
        if (self::isNumericStringForIncDec($str)) {
            $this->storeNumericStringIncDec($str, -1);

            return;
        }
        // php-src zend_operators.c — non-numeric string -- is a no-op with E_DEPRECATED (#29088).
        self::warnDecrementNonNumericString($vm, $frame);
        $this->string($str);
    }

    /**
     * PHP 8.3+ E_DEPRECATED for `$s++` when `$s` is empty or not strictly alphanumeric (#29658).
     *
     * Same profile gate as bool/null no-effect warnings ({@see supportsIncDecNoEffectWarning}).
     */
    private static function warnIncrementNonAlphanumericString(
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        $context = $vm?->context ?? $frame?->vmContext;
        if (null === $context) {
            return;
        }
        $context->errors->internalDeprecated(
            'Increment on non-alphanumeric string is deprecated',
            $context,
            $frame,
            '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null
        );
    }

    /**
     * PHP 8.3+ E_DEPRECATED for `$s--` / `--$s` when `$s` is '' (#29658).
     */
    private static function warnDecrementEmptyString(
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        $context = $vm?->context ?? $frame?->vmContext;
        if (null === $context) {
            return;
        }
        $context->errors->internalDeprecated(
            'Decrement on empty string is deprecated as non-numeric',
            $context,
            $frame,
            '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null
        );
    }

    /**
     * PHP 8.3+ E_DEPRECATED for `$s--` / `--$s` when `$s` is a non-numeric string (#29088).
     *
     * Same profile gate as bool/null no-effect warnings ({@see supportsIncDecNoEffectWarning}).
     */
    private static function warnDecrementNonNumericString(
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        $context = $vm?->context ?? $frame?->vmContext;
        if (null === $context) {
            return;
        }
        $context->errors->internalDeprecated(
            'Decrement on non-numeric string has no effect and is deprecated',
            $context,
            $frame,
            '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null
        );
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
            // Same INT_MAX/MIN → float promote as applyIncrement/Decrement (#29144).
            $next = (int) $str + $delta;
            if (\is_int($next)) {
                $this->int($next);
            } else {
                $this->float((float) $next);
            }
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
     * Zend E_WARNING text for no-op ++/-- on bool/null (zend_operators.c, #26378).
     */
    public static function incDecNoEffectWarningMessage(string $verb, string $typeName): string
    {
        return sprintf(
            '%s on type %s has no effect, this will change in the next major version of PHP',
            $verb,
            $typeName
        );
    }

    /**
     * Emit PHP 8.3+ no-effect ++/-- warning when profile-gated and a live ErrorReporter exists.
     */
    private static function warnIncDecNoEffect(
        string $verb,
        string $typeName,
        ?\PHPCompiler\VM $vm = null,
        ?\PHPCompiler\Frame $frame = null
    ): void {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        $context = $vm?->context ?? $frame?->vmContext;
        if (null === $context) {
            return;
        }
        $context->errors->triggerError(
            self::incDecNoEffectWarningMessage($verb, $typeName),
            ErrorReporter::E_WARNING,
            '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
            $context,
            $frame
        );
    }

    /**
     * Zend increment_function() on a single value (issue #3552).
     *
     * Optional $vm/$frame emit PHP 8.3+ "has no effect" E_WARNING for bool (#26378).
     */
    public function applyIncrement(?\PHPCompiler\VM $vm = null, ?\PHPCompiler\Frame $frame = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $copy = new self();
            $copy->copyFrom($this->indirect);
            $copy->applyIncrement($vm, $frame);
            $this->indirect->copyFrom($copy);

            return;
        }
        switch ($this->type) {
            case self::TYPE_BOOLEAN:
                // PHP 8.2+ zend_operators.c: bool inc/dec is a no-op (issue #7058, re-#4727).
                // PHP 8.3+: E_WARNING — will change in next major (RFC saner-inc-dec-operators, #26378).
                self::warnIncDecNoEffect('Increment', 'bool', $vm, $frame);

                return;
            case self::TYPE_UNDEFINED:
            case self::TYPE_NULL:
                // Zend increment_function(): IS_NULL → int 0 then ++ (issue #7435). No 8.3 warning.
                $this->int(1);

                return;
            case self::TYPE_INTEGER:
                if ($this->isVmResource()) {
                    throw new \TypeError('Cannot increment resource');
                }
                // zend_operators.h fast_long_increment_function — PHP_INT_MAX → double (#29144).
                // Never ++$this->integer: host typed int property TypeErrors as Variable::$integer.
                if (\PHP_INT_MAX === $this->integer) {
                    $this->float(VmIncDec::overflowIncrementFloat());

                    return;
                }
                ++$this->integer;

                return;
            case self::TYPE_FLOAT:
                $this->float += 1;

                return;
            case self::TYPE_STRING_OFFSET:
                throw new \Error(self::STRING_OFFSET_INCDEC_ERROR);
            case self::TYPE_STRING:
                $this->applyStringIncDec(OpCode::TYPE_PLUS, $this->string, $vm, $frame);

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
     *
     * Optional $vm/$frame emit PHP 8.3+ "has no effect" E_WARNING for bool and null (#26378).
     */
    public function applyDecrement(?\PHPCompiler\VM $vm = null, ?\PHPCompiler\Frame $frame = null): void
    {
        if ($this->type === self::TYPE_INDIRECT) {
            $copy = new self();
            $copy->copyFrom($this->indirect);
            $copy->applyDecrement($vm, $frame);
            $this->indirect->copyFrom($copy);

            return;
        }
        switch ($this->type) {
            case self::TYPE_BOOLEAN:
                // PHP 8.2+ zend_operators.c: bool inc/dec is a no-op (issue #7058, re-#4727).
                // PHP 8.3+: E_WARNING — will change in next major (RFC saner-inc-dec-operators, #26378).
                self::warnIncDecNoEffect('Decrement', 'bool', $vm, $frame);

                return;
            case self::TYPE_UNDEFINED:
            case self::TYPE_NULL:
                // Zend decrement_function(): IS_NULL is a no-op on PHP 8.x (issue #7435).
                // PHP 8.3+: E_WARNING for null -- (null ++ still coerces to 1 without this warning).
                self::warnIncDecNoEffect('Decrement', 'null', $vm, $frame);

                return;
            case self::TYPE_INTEGER:
                if ($this->isVmResource()) {
                    throw new \TypeError('Cannot decrement resource');
                }
                // zend_operators.h fast_long_decrement_function — PHP_INT_MIN → double (#29144).
                if (\PHP_INT_MIN === $this->integer) {
                    $this->float(VmIncDec::overflowDecrementFloat());

                    return;
                }
                --$this->integer;

                return;
            case self::TYPE_FLOAT:
                $this->float -= 1;

                return;
            case self::TYPE_STRING_OFFSET:
                throw new \Error(self::STRING_OFFSET_INCDEC_ERROR);
            case self::TYPE_STRING:
                $this->applyStringIncDec(OpCode::TYPE_MINUS, $this->string, $vm, $frame);

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
                    // zend_operators.c zendi_negate_function — PHP_INT_MIN overflows to double (#28761).
                    $negated = -$resolved->integer;
                    if (\is_int($negated)) {
                        $this->int($negated);
                    } else {
                        $this->float($negated);
                    }

                    return;
                }
                if ($resolved->type === Variable::TYPE_FLOAT) {
                    $this->copyFrom($resolved);
                    $this->float *= -1.0;

                    return;
                }
                $opCtx = $frame?->vmContext ?? $vm?->context;
                if (null !== $opCtx
                    && (\PHPCompiler\ext\bcmath\VmBcMathNumber::tryUnaryMinus($this, $resolved, $opCtx)
                        || \PHPCompiler\ext\gmp\VmGmpObject::tryUnaryMinus($this, $resolved, $opCtx))) {
                    return;
                }
                $number = self::coerceUnaryPlusOperand($resolved, $vm, $frame);
                // Same INT_MIN → float promotion as the TYPE_INTEGER branch (#28761).
                $negated = -$number;
                if (\is_int($negated)) {
                    $this->int($negated);
                } else {
                    $this->float((float) $negated);
                }

                return;
            case OpCode::TYPE_BOOLEAN_NOT:
                // Class-const / default fold path shares unaryOp with runtime (#23997).
                $this->bool(!($expr->resolveIndirect()->toBool()));

                return;
            case OpCode::TYPE_BITWISE_NOT:
                $resolved = $expr->resolveIndirect();
                if (self::isEnumCaseOperand($resolved)) {
                    throw new \TypeError(sprintf(
                        'Cannot perform bitwise not on %s',
                        self::operandEnumClassName($resolved)
                    ));
                }
                $gmpCtx = $frame?->vmContext ?? $vm?->context;
                if (null !== $gmpCtx
                    && \PHPCompiler\ext\gmp\VmGmpObject::tryBitwiseNot($this, $resolved, $gmpCtx)) {
                    return;
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
                        self::TYPE_BOOLEAN === $resolved->type
                            ? ($resolved->bool ? 'true' : 'false')
                            : 'null'
                    ));
                }
                if ($resolved->type === self::TYPE_ARRAY) {
                    throw new \TypeError('Cannot perform bitwise not on array');
                }
                if ($resolved->type === self::TYPE_OBJECT) {
                    throw new \TypeError(sprintf(
                        'Cannot perform bitwise not on %s',
                        $resolved->toObject()->class->name
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
        $byte = $this->byteFromAssignValue($value);
        if ($index > $len) {
            $str .= str_repeat(' ', $index - $len);
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

    private function byteFromAssignValue(self $value): string
    {
        $value = $value->resolveIndirect();
        if (self::TYPE_NULL === $value->type) {
            throw new \Error(self::STRING_OFFSET_EMPTY_ASSIGN_ERROR);
        }
        switch ($value->type) {
            case self::TYPE_STRING:
                $s = $value->string;
                if ('' === $s) {
                    throw new \Error(self::STRING_OFFSET_EMPTY_ASSIGN_ERROR);
                }
                $this->warnIfMultiByteStringOffsetAssign($s);

                return $s[0];
            case self::TYPE_INTEGER:
                // Zend convert_to_string then first byte — not chr()/code-unit (#25778).
                $s = (string) $value->integer;
                $this->warnIfMultiByteStringOffsetAssign($s);

                return $s[0];
            case self::TYPE_ARRAY:
                // Zend zend_assign_to_string_offset → convert_to_string → Array warning (#22925).
                self::emitArrayToStringWarning(null, $this->stringOffsetFrame, $this->stringOffsetContext);
                $s = 'Array';
                $this->warnIfMultiByteStringOffsetAssign($s);

                return $s[0];
            case self::TYPE_OBJECT:
                // Zend convert_to_string → __toString first byte; Error without (#25794).
                if (null === $this->stringOffsetContext) {
                    throw new \LogicException('String offset object assign requires VM context');
                }
                $s = $this->stringOffsetContext->runtime->vm()->castObjectToString($value->toObject());
                if ('' === $s) {
                    throw new \Error(self::STRING_OFFSET_EMPTY_ASSIGN_ERROR);
                }
                $this->warnIfMultiByteStringOffsetAssign($s);

                return $s[0];
            default:
                $s = $value->toString(null, $this->stringOffsetFrame);
                if ('' === $s) {
                    throw new \Error(self::STRING_OFFSET_EMPTY_ASSIGN_ERROR);
                }
                $this->warnIfMultiByteStringOffsetAssign($s);

                return $s[0];
        }
    }

    /**
     * Zend/zend_execute.c zend_assign_to_string_offset() — E_WARNING when RHS string length > 1 (#22380).
     */
    private function warnIfMultiByteStringOffsetAssign(string $s): void
    {
        if (\strlen($s) <= 1 || null === $this->stringOffsetReporter) {
            return;
        }
        $this->stringOffsetReporter->onlyFirstByteAssignedToStringOffset(
            $this->stringOffsetContext,
            $this->stringOffsetFrame,
            $this->stringOffsetFile
        );
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
