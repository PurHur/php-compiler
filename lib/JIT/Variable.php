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
use PHPCompiler\OpCode;
use PHPCompiler\Web\Superglobals;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\VM\Variable as VMVariable;

use PHPLLVM;

final class Variable {
    const TYPE_NULL = 0;
    const TYPE_NATIVE_LONG = 1;
    const TYPE_NATIVE_BOOL = 2;
    const TYPE_NATIVE_DOUBLE = 3;

    const IS_NATIVE_ARRAY = 1 << 6;
    const IS_REFCOUNTED   = 1 << 7;

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

    /** Foreach by-ref: int1 phi selecting packed-index vs string-key writable arm (#4364). */
    public ?\PHPLLVM\Value $foreachByRefPackedArm = null;

    /** Boxed foreach / SplObjectStorage offset key for $arr[$key] = … (issue #86). */
    public ?Variable $writableValueBoxKey = null;

    /** Writable ArrayAccess $obj[$key] assignment target (#3331, #4012). */
    public ?Variable $writableArrayAccessReceiver = null;

    public ?Variable $writableArrayAccessKey = null;

    public bool $isArrayAccessWritableOffset = false;

    /** String literal value when this variable represents a constant string operand. */
    public ?string $compileTimeString = null;

    /** Float literal value when this variable represents a constant double operand. */
    public ?float $compileTimeFloat = null;

    /** Integer literal value when this variable represents a constant long operand. */
    public ?int $compileTimeLong = null;

    /** User/global constant name when this variable holds a compile-time const fetch. */
    public ?string $compileTimeConstantName = null;

    /** Builtin/user enum case when this variable is a compile-time enum singleton (#7260). */
    public ?array $compileTimeEnumCase = null;

    /**
     * BcMath\Number value/scale when constructed (or folded) from compile-time literals (#24683).
     *
     * @var array{value: string, scale: int}|null
     */
    public ?array $compileTimeBcmathNumber = null;

    /**
     * DOMElement tagName when created via createElement('lit') in user-script AOT (#26765).
     */
    public ?string $compileTimeDomTagName = null;

    /**
     * DatePeriod end-date form — ordered Unix timestamps for foreach snapshot (#26772).
     *
     * @var list<int>|null
     */
    public ?array $compileTimeDatePeriodTimestamps = null;

    /** Timezone name for {@see $compileTimeDatePeriodTimestamps} DateTimeImmutable values (#26772). */
    public ?string $compileTimeDatePeriodTimezone = null;

    /**
     * DateInterval::__construct() parse when $duration is a compile-time string (#26772).
     *
     * @var array{y:int,m:int,d:int,h:int,i:int,s:int,f:float,invert:int}|null
     */
    public ?array $compileTimeDateInterval = null;

    /** Set when this variable is the PHP {@code null} constant (const-fetch). */
    public bool $isNullConstant = false;

    /** Placeholder for a skipped optional parameter after named-arg densification (#9525). */
    public bool $isOptionalOmittedNamedArg = false;

    /** Typed variadic named pack: element checks done at compile time (#18647). */
    public bool $variadicElementChecksDone = false;

    /** {@see __value__} slot holds a nested {@see __hashtable__} (e.g. $_FILES['field']). */
    public bool $valueBoxHashtable = false;

    /** Hashtable pointer from {@see __value__readHashtable}; do not addref/delref (issue #107). */
    public bool $borrowedHashtable = false;

    /** Borrowed {@see __value__} entry from foreach by-ref; skip valueDelref (#4364). */
    public bool $borrowedValueEntry = false;

    /**
     * Dead php-cfg Concat temp in echo/call args (#23798, #23842, #24024).
     *
     * Skip delref in freeDeadVariables — ownership is an entry alloca that outlives
     * the block. Each ConcatList link gets its own entry alloca so LLVM cannot
     * stack-color dead fopen() value boxes into concat temps (#24024).
     */
    public bool $ephemeralConcatTemp = false;

    /**
     * By-ref actual is a call/method return temp — notice already emitted; mutator may use it (#25815).
     */
    public bool $nonVariableByRefTempAllowed = false;

    /** void** property slot on {@see __object__} when this variable is a property lvalue (#58). */
    public ?\PHPLLVM\Value $objectPropertySlot = null;

    /** Value-box alloca for `new Variable()` temps after copyFrom in NestedJIT (#24156). */
    public ?\PHPLLVM\Value $nestedHelperValueSlot = null;

    /** Module global backing a static property (issue #1225). */
    public ?\PHPLLVM\Value $staticPropertyGlobal = null;

    /** Declared JIT type when {@see $staticPropertyGlobal} is set. */
    public ?int $staticPropertyType = null;

    /** i1 init flag for typed static properties without compile-time default (#5047). */
    public ?\PHPLLVM\Value $staticPropertyInitGlobal = null;

    /** DNF declared-type arms for static property writes (#8726). */
    public ?array $staticPropertyDnfArms = null;

    /** Declaring class lc for static property set-hook dispatch (#4807). */
    public ?string $staticPropertyHookClassLc = null;

    /** Callee slot for a literal-include caller local; skip delref in unrelated assigns (#866). */
    public bool $includeBinding = false;

    /** Declared JIT property type when {@see $objectPropertySlot} is set. */
    public ?int $objectPropertyType = null;

    /** Owning {@see __object__} when this variable is an instance property lvalue (#1360). */
    public ?\PHPLLVM\Value $objectPropertyReceiver = null;

    /** Declared property name for readonly diagnostics (#1360). */
    public ?string $objectPropertyName = null;

    /** Declaring class name for readonly diagnostics (#1360). */
    public ?string $objectPropertyClassName = null;

    /**
     * DNF declared-type arms for property writes (#4111).
     *
     * @var list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>|null
     */
    public ?array $objectPropertyDnfArms = null;

    /** __set dispatch when the property slot does not exist (#146, #4022). */
    public ?\PHPLLVM\Value $magicSetReceiver = null;

    public ?string $magicSetName = null;

    /** __get return value; non-object dim-write must error (#4673). Objects may write_dimension (#20005). */
    public ?string $magicGetOverloadedClass = null;

    public ?string $magicGetOverloadedName = null;

    /** Native call proxy when this object is a JIT-lowered closure (#72). */
    public ?Call $closureCall = null;

    /** Anonymous `static function` — cannot bind $this (Zend zend_closures.c, #4613). */
    public bool $closureIsStatic = false;

    /** fromCallable / FCC method wrapper — unbind uses method warning (#23421). */
    public bool $closureIsMethodFake = false;

    /** Heap {@see __generator_state__*} for JIT Generator objects (#3074). */
    public ?\PHPLLVM\Value $generatorStatePtr = null;

    public ?string $generatorResumeName = null;

    public ?string $fiberResumeName = null;

    public ?\PHPLLVM\Value $fiberStatePtr = null;

    /** Compile-time class for `new Foo` results — survives VALUE-box assign (#26825). */
    public ?string $classUserType = null;

    /** MCJIT/AOT foreach over a {@see Generator} object (#3074, #3115). */
    public bool $isJitGenerator = false;

    /** Live {@see __value__*} for closure use (&$var) capture slots (issue #72). */
    public ?\PHPLLVM\Value $valueBoxAliasPtr = null;

    /** Module-global {@see __value__*} slot for function-local static storage (#3778, #2286). */
    public bool $functionStaticGlobal = false;

    /** Top-level script / $GLOBALS symbol slot — skip valueDelref on scope exit (#4423). */
    public bool $scriptGlobalSlot = false;

    /** Inline `[]` literal with no elements yet at JIT emit time (#11729). */
    public bool $compileTimeEmptyArrayLiteral = false;

    /**
     * Packed string literals from INIT_ARRAY / `$a[]=` when every element had
     * {@see $compileTimeString} (#27181 preg_filter/replace fold).
     *
     * @var array<int, string>|null
     */
    public ?array $compileTimeArray = null;

    private static int $lvalueCounter = 0;
    public int $nextFreeElement = 0;

    /**
     * After a runtime spread loop, packed appends must load nextFreeElement from the
     * hashtable struct — the compile-time counter is stale (#23971).
     */
    public bool $nextFreeElementFromRuntime = false;

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
            case VMVariable::TYPE_NULL: return self::TYPE_NULL;
            case VMVariable::TYPE_INTEGER: return self::TYPE_NATIVE_LONG;
            case VMVariable::TYPE_FLOAT: return self::TYPE_NATIVE_DOUBLE;
            case VMVariable::TYPE_BOOLEAN: return self::TYPE_NATIVE_BOOL;
            case VMVariable::TYPE_STRING: return self::TYPE_STRING;
            case VMVariable::TYPE_OBJECT: return self::TYPE_OBJECT;
            case VMVariable::TYPE_ARRAY: return self::TYPE_HASHTABLE;
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

    /** Zend 8+ dim fetch on scalar containers (#4713). */
    public static function cannotUseBracketLabel(int $type): ?string
    {
        switch ($type) {
            case self::TYPE_NULL:
                return 'null';
            case self::TYPE_NATIVE_BOOL:
                return 'bool';
            case self::TYPE_NATIVE_LONG:
                return 'int';
            case self::TYPE_NATIVE_DOUBLE:
                return 'float';
            default:
                return null;
        }
    }

    /** Zend property fetch on non-object receivers (#5276). */
    public static function propertyFetchNonObjectTypeLabel(int $type): ?string
    {
        $label = self::cannotUseBracketLabel($type);
        if (null !== $label) {
            return $label;
        }
        switch ($type) {
            case self::TYPE_STRING:
                return 'string';
            case self::TYPE_HASHTABLE:
                return 'array';
            default:
                return null;
        }
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
        if (null !== $type->userType && 0 === strcasecmp($type->userType, 'mixed')) {
            return self::TYPE_VALUE;
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
     * Element JIT type when a static array literal may use a homogeneous native LLVM array.
     *
     * @param list<Type> $subTypes
     */
    private static function homogeneousNativeElementType(array $subTypes): ?int
    {
        if ([] === $subTypes) {
            return null;
        }
        foreach ($subTypes as $sub) {
            $userType = $sub->userType ?? '';
            if ('' !== $userType && 0 !== strcasecmp($userType, 'mixed')) {
                // Enum cases and class-valued literals must use hashtable slots (#5722, #5638).
                return null;
            }
        }
        $elemType = self::getTypeFromType($subTypes[0]);
        if (!in_array($elemType, [
            self::TYPE_NATIVE_LONG,
            self::TYPE_NATIVE_BOOL,
            self::TYPE_NATIVE_DOUBLE,
            self::TYPE_STRING,
            self::TYPE_OBJECT,
        ], true)) {
            return null;
        }
        foreach ($subTypes as $sub) {
            if (self::getTypeFromType($sub) !== $elemType) {
                return null;
            }
        }

        return $elemType;
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
        if (self::isVariadicParamOperand($block, $op)) {
            return new Variable(
                $context,
                self::TYPE_HASHTABLE,
                self::KIND_VARIABLE,
                BasicBlockHelper::entryAllocaForFunction($context, $func, $context->getTypeFromString('__hashtable__*'))
            );
        }
        $name = OperandName::resolve($op);
        if (
            null !== $name
            && '' !== $name
            && !Superglobals::isSuperglobalName($name)
            && $block->isMainScript()
            && !$context->isForeachByRefLocalName($name, $block)
        ) {
            return $context->ensureScriptGlobal($name);
        }
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
                if (!is_null($size) && $size > 0 && !$context->analyzer->hasDynamicArrayAppend($op, $size)) {
                    $subTypes = $op->type->subTypes ?? [];
                    $origType = self::homogeneousNativeElementType($subTypes);
                    if (null !== $origType) {
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
            BasicBlockHelper::entryAllocaForFunction($context, $func, $context->getTypeFromString($stringType))
        );
    }

    private static function isVariadicParamOperand(Block $block, Operand $op): bool
    {
        if (null === $block->variadicParamIndex) {
            return false;
        }
        $slot = $block->slotForOperand($op);
        if (null === $slot) {
            return false;
        }
        foreach ($block->opCodes as $recv) {
            if (OpCode::TYPE_ARG_RECV !== $recv->type) {
                continue;
            }
            if ((int) $recv->arg1 === $slot && (int) $recv->arg2 === $block->variadicParamIndex) {
                return true;
            }
        }

        return false;
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
        } elseif ('double' === $llvmType) {
            $type = self::TYPE_NATIVE_DOUBLE;
        } elseif ('int64' === $llvmType) {
            $type = self::TYPE_NATIVE_LONG;
        } elseif ('int1' === $llvmType) {
            $type = self::TYPE_NATIVE_BOOL;
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
                $literal = (int) $op->value;
                break;
            case self::TYPE_STRING:
                // Mirror JitValueBox::alloc — entryAlloca with cleared insert leaves the
                // separate/store parentless under NestedJIT / M5 argv (#26756).
                BasicBlockHelper::ensureOpenInsertBlock($context, 'string_literal_alloc_cont');
                $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
                $resume = BasicBlockHelper::tryGetInsertBlock($context);
                $global = $context->constantStringFromString($op->value);
                // constantStringFromString may emit on a temp init builder; always resume the
                // caller's open BB before load/separate/store (#26756).
                if (null !== $resume) {
                    BasicBlockHelper::restoreInsertBlock($context, $resume);
                } else {
                    BasicBlockHelper::ensureOpenInsertBlock($context, 'string_literal_init_cont');
                }
                $loaded = $context->builder->load($global);
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $loaded
                );
                $context->builder->store($owned, $slot);
                // Keep insert on the open store BB — callers loadValue/compare immediately and
                // NestedJIT may have cleared insert mid-fromLiteral (#26756).
                BasicBlockHelper::ensureOpenInsertBlock($context, 'string_literal_after_store_cont');
                $var = new Variable(
                    $context,
                    self::TYPE_STRING,
                    self::KIND_VARIABLE,
                    $slot
                );
                if (is_string($op->value)) {
                    $var->compileTimeString = $op->value;
                }

                return $var;
            case self::TYPE_NATIVE_DOUBLE:
                $floatValue = \is_float($op->value)
                    ? $op->value
                    : (is_numeric($op->value) ? (float) $op->value : null);
                if (null === $floatValue) {
                    throw new \LogicException('Expected numeric float literal');
                }
                $value = $context->constantFromFloat($floatValue, self::getStringType($type));
                $literal = $floatValue;
                break;
            case self::TYPE_NATIVE_BOOL:
                $value = $context->constantFromBool($op->value);
                $literal = $op->value ? 1 : 0;
                break;
            case self::TYPE_NULL:
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $nullVar = new Variable(
                    $context,
                    self::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $nullVar->isNullConstant = true;

                return $nullVar;
            case self::TYPE_VALUE:
                $slot = JitValueBox::alloc($context);
                $ptr = JitValueBox::pointer($context, $slot);
                // Preserve scalar immediates on TYPE_VALUE boxes (#23427).
                $boxedCompileTimeLong = null;
                if (null === $op->value) {
                    $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                } elseif (is_int($op->value)) {
                    $context->builder->call(
                        $context->lookupFunction('__value__writeLong'),
                        $ptr,
                        $context->constantFromInteger($op->value)
                    );
                    $boxedCompileTimeLong = (int) $op->value;
                } elseif (is_bool($op->value)) {
                    $context->builder->call(
                        $context->lookupFunction('__value__writeBool'),
                        $ptr,
                        $context->getTypeFromString('int32')->constInt($op->value ? 1 : 0, false)
                    );
                    $boxedCompileTimeLong = $op->value ? 1 : 0;
                } elseif (is_float($op->value)) {
                    $context->builder->call(
                        $context->lookupFunction('__value__writeDouble'),
                        $ptr,
                        $context->constantFromFloat($op->value)
                    );
                } elseif (is_string($op->value)) {
                    $str = $context->builder->load($context->constantStringFromString($op->value));
                    $context->builder->call(
                        $context->lookupFunction('__value__writeString'),
                        $ptr,
                        $str
                    );
                    $literal = $op->value;
                } else {
                    $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                }
                $var = new Variable(
                    $context,
                    self::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                if (null === $op->value) {
                    // By-value null literals are lowered as TYPE_VALUE boxes; keep the
                    // compile-time null flag so builtins can TypeError like TYPE_NULL (#19845).
                    $var->isNullConstant = true;
                }
                if (isset($literal)) {
                    $var->compileTimeString = $literal;
                }
                if (null !== $boxedCompileTimeLong) {
                    $var->compileTimeLong = $boxedCompileTimeLong;
                }

                return $var;
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
            if (\is_string($literal)) {
                $var->compileTimeString = $literal;
            } elseif (\is_int($literal)) {
                $var->compileTimeLong = $literal;
            } elseif (\is_float($literal)) {
                $var->compileTimeFloat = $literal;
            }
        }

        return $var;
    }

    public static function fromConstantInt(Context $context, int $value): Variable {
        $var = new Variable(
            $context,
            self::TYPE_NATIVE_LONG,
            self::KIND_VALUE,
            $context->constantFromInteger($value)
        );
        $var->compileTimeLong = $value;

        return $var;
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
                            $this->context->builder->zExt($this->value, $this->context->getTypeFromString('long long'))
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
        if (null !== $this->superglobalName) {
            return;
        }
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
        if ($this->scriptGlobalSlot) {
            return;
        }
        // KIND_VARIABLE $_SESSION re-loads sg_* — must not delref process-owned HT (#26411).
        if (null !== $this->superglobalName) {
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
            if (
                self::KIND_VARIABLE === $this->kind
                && null === $this->objectPropertySlot
                && !$this->borrowedValueEntry
            ) {
                $assert = getenv('PHP_COMPILER_LLVM_ASSERT');
                if ('1' === $assert || 'true' === strtolower((string) $assert)) {
                    $slotTy = $this->context->getStringFromType($this->value->typeOf());
                    if ('__value__*' !== $slotTy && '__value__value*' !== $slotTy) {
                        throw new \LogicException(
                            'Variable::free TYPE_VALUE slot is '.$slotTy.' (want __value__*) — #22642'
                        );
                    }
                }
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__valueDelref'),
                    $this->value
                );
            }

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
            if ($this->ephemeralConcatTemp) {
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
        // Never null out sg_SESSION / other process-owned superglobal slots (#26411).
        if (null !== $this->superglobalName) {
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
                // Zend zend_check_string_offset — reject illegal dims before coerce (#22895).
                if (self::emitIllegalStringOffsetDimGuard($this->context, $dim)) {
                    return new Variable(
                        $this->context,
                        self::TYPE_NULL,
                        self::KIND_VALUE,
                        $this->context->getTypeFromString('__value__*')->constNull()
                    );
                }
                $dim = self::coerceStringOffsetDimToLong($this->context, $dim);
                $str = $this->context->helper->loadValue($this);
                if ($forWrite) {
                    $charPtr = StringOffsetHelper::dimFetch(
                        $this->context,
                        $str,
                        $dim
                    );

                    return new Variable(
                        $this->context,
                        self::TYPE_STRING,
                        self::KIND_VALUE,
                        $charPtr,
                    );
                }
                $str = StringOffsetHelper::readDimAsString($this->context, $str, $dim);

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
                if ('GLOBALS' === $container->superglobalName) {
                    return GlobalsTableInit::offsetFetch($this->context, $dim, $forWrite);
                }
                if (
                    !$forWrite
                    && null !== $container->superglobalName
                    && '_FILES' !== $container->superglobalName
                    && (self::TYPE_STRING === $dim->type || self::TYPE_VALUE === $dim->type)
                    && (null === $expectedType || Type::TYPE_ARRAY !== $expectedType->type)
                ) {
                    $key = $dim->compileTimeString;
                    if (null !== $key && self::TYPE_STRING === $dim->type) {
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
                        $ht = HashTableHelper::loadHashtablePointer($this->context, $container);
                        $keyVal = $this->context->helper->loadValue($dim);

                        return HashTableHelper::readSuperglobalStringKeyToValueBox(
                            $this->context,
                            $ht,
                            $keyVal
                        );
                    }
                    if (self::TYPE_VALUE === $dim->type) {
                        $ht = HashTableHelper::loadHashtablePointer($this->context, $container);

                        return HashTableHelper::readDimToValueBox(
                            $this->context,
                            $ht,
                            $dim,
                            $container->superglobalName
                        );
                    }
                }
                $ht = HashTableHelper::loadHashtablePointer($this->context, $container);
                if (self::TYPE_VALUE === $dim->type) {
                    if ($forWrite) {
                        return HashTableHelper::prepareValueBoxKeyWrite($this->context, $ht, $dim);
                    }

                    return HashTableHelper::readDimToValueBox(
                        $this->context,
                        $ht,
                        $dim,
                        $container->superglobalName
                    );
                }
                if (self::TYPE_OBJECT === $dim->type) {
                    if ($forWrite) {
                        HashTableHelper::emitIllegalOffsetType($this->context);
                        $this->context->builder->call($this->context->lookupFunction('abort'));
                        $this->context->builder->clearInsertionPosition();

                        return new Variable(
                            $this->context,
                            self::TYPE_NULL,
                            self::KIND_VALUE,
                            $this->context->getTypeFromString('__value__*')->constNull()
                        );
                    }
                    $keyObj = $this->context->helper->loadValue($dim);

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
                $index = self::materializePackedIndex($this->context, $dim, $forWrite);
                // Scalar $arr[i]=… uses prepareIndexWrite; nested FETCH_DIM_W ($arr[i][j]=…)
                // must return the live child HT so the inner write persists (#24011; string keys
                // already branch on TYPE_ARRAY above — zend_execute.c ZEND_FETCH_DIM_W).
                if ($forWrite && (null === $expectedType || Type::TYPE_ARRAY !== $expectedType->type)) {
                    return HashTableHelper::prepareIndexWrite($this->context, $ht, $index);
                }
                if ($forWrite && null !== $expectedType && Type::TYPE_ARRAY === $expectedType->type) {
                    $childHt = HashTableHelper::readIndexedHashtable($this->context, $ht, $index);

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
                if (!$forWrite) {
                    return $this->dimFetchValueBoxRead($dim, $expectedType);
                }
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
     * Float dims on write emit Zend precision-loss E_DEPRECATED (#19730).
     */
    private static function materializePackedIndex(Context $context, self $dim, bool $forWrite = false): \PHPLLVM\Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (self::TYPE_NATIVE_DOUBLE === $dim->type) {
            $doubleVal = $context->helper->loadValue($dim);
            if ($forWrite) {
                $truncated = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning($context, $doubleVal);
            } else {
                // Read/isset/unset: finite fractional keys stay silent (#16739 / #27948);
                // INF/NAN still E_DEPRECATED (#27926).
                $truncated = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithNonFinitePrecisionWarning(
                    $context,
                    $doubleVal
                );
            }

            return $context->builder->truncOrBitCast($truncated, $sizeT);
        }
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

    /**
     * Read dim on a {@see TYPE_VALUE} box — string-tagged boxes use offset semantics (#22646).
     *
     * Must not call {@see HashTableHelper::ensureHashtablePointer} first: that allocates an empty
     * hashtable and {@see __value__writeHashtable} clobbers the string, so `$s[0]` echoed empty.
     *
     * String/object keys stay on the hashtable arm only: emitting {@see StringOffsetHelper::dimFetch}
     * with a string dim poisons the module (`normalize(%__string__*, i64)` vs `(i64, i64)`).
     */
    private function dimFetchValueBoxRead(self $dim, ?Type $expectedType): Variable
    {
        if (self::TYPE_STRING === $dim->type || self::TYPE_OBJECT === $dim->type) {
            $childHt = HashTableHelper::loadHashtablePointer($this->context, $this);
            $htVar = new Variable(
                $this->context,
                self::TYPE_HASHTABLE,
                self::KIND_VALUE,
                $childHt
            );
            $htVar->borrowedHashtable = true;

            return $htVar->dimFetch($dim, $expectedType, false);
        }

        $ptr = JitValueBox::valuePtrFromVariable($this->context, $this);
        $map = $this->context->structFieldMap['__value__'];
        $i8 = $this->context->getTypeFromString('int8');
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($ptr, $map['type'])
        );
        $isString = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $this->context->builder->and($typeByte, $i8->constInt(0x7f, false)),
            $i8->constInt(self::TYPE_STRING & 0x7f, false)
        );

        $resultSlot = JitValueBox::alloc($this->context);
        $resultPtr = JitValueBox::pointer($this->context, $resultSlot);

        $strBlock = BasicBlockHelper::append($this->context, 'value_dim_string');
        $htBlock = BasicBlockHelper::append($this->context, 'value_dim_ht');
        $doneBlock = BasicBlockHelper::append($this->context, 'value_dim_done');
        $this->context->builder->branchIf($isString, $strBlock, $htBlock);

        $this->context->builder->positionAtEnd($strBlock);
        $str = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $ptr
        );
        // Numeric dim only (see guard above). VALUE dims keep their box — normalize coerces via readLong.
        $dimLong = $dim;
        if (self::TYPE_NATIVE_DOUBLE === $dim->type || self::TYPE_NATIVE_BOOL === $dim->type) {
            $dimLong = $dim->castTo(self::TYPE_NATIVE_LONG);
        }
        $strResult = StringOffsetHelper::readDimAsString($this->context, $str, $dimLong);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeString'),
            $resultPtr,
            $strResult
        );
        $this->context->builder->branch($doneBlock);

        $this->context->builder->positionAtEnd($htBlock);
        $childHt = HashTableHelper::loadHashtablePointer($this->context, $this);
        $htVar = new Variable(
            $this->context,
            self::TYPE_HASHTABLE,
            self::KIND_VALUE,
            $childHt
        );
        $htVar->borrowedHashtable = true;
        $htFetched = $htVar->dimFetch($dim, $expectedType, false);
        JitValueBox::assignToPointer($this->context, $resultPtr, $htFetched);
        $this->context->builder->branch($doneBlock);

        $this->context->builder->positionAtEnd($doneBlock);

        return new Variable(
            $this->context,
            self::TYPE_VALUE,
            self::KIND_VARIABLE,
            $resultSlot
        );
    }

    /**
     * Compile-time zend_check_string_offset guard for known illegal dims (#22895).
     *
     * @return bool true when a TypeError was emitted (caller should return a dummy)
     */
    private static function emitIllegalStringOffsetDimGuard(Context $context, self $dim): bool
    {
        if (self::TYPE_HASHTABLE === $dim->type) {
            HashTableHelper::emitIllegalOffsetType(
                $context,
                \PHPCompiler\VM\StringOffsetJitHelper::illegalDimTypeErrorMessage('array')
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->clearInsertionPosition();

            return true;
        }
        if (self::TYPE_OBJECT === $dim->type) {
            // Compile-time class name is not always on the JIT Variable; Zend uses the live class.
            // Prefer overloaded/property class hints when present (#22895).
            $className = $dim->magicGetOverloadedClass
                ?? $dim->objectPropertyClassName
                ?? 'object';
            HashTableHelper::emitIllegalOffsetType(
                $context,
                \PHPCompiler\VM\StringOffsetJitHelper::illegalDimTypeErrorMessage($className)
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->clearInsertionPosition();

            return true;
        }
        if (self::TYPE_STRING === $dim->type && null !== $dim->compileTimeString) {
            $parsed = VMVariable::tryParseStringOffsetLong($dim->compileTimeString);
            if (null === $parsed) {
                HashTableHelper::emitIllegalOffsetType(
                    $context,
                    \PHPCompiler\VM\StringOffsetJitHelper::illegalDimTypeErrorMessage('string')
                );
                $context->builder->call($context->lookupFunction('abort'));
                $context->builder->clearInsertionPosition();

                return true;
            }
        }

        return false;
    }

    /**
     * Coerce a compile-time numeric string dim to native long for string-offset LLVM (#22895).
     */
    private static function coerceStringOffsetDimToLong(Context $context, self $dim): self
    {
        if (self::TYPE_STRING !== $dim->type || null === $dim->compileTimeString) {
            return $dim;
        }
        $parsed = VMVariable::tryParseStringOffsetLong($dim->compileTimeString);
        if (null === $parsed) {
            return $dim;
        }

        return new self(
            $context,
            self::TYPE_NATIVE_LONG,
            self::KIND_VALUE,
            $context->constantFromInteger($parsed[0])
        );
    }
}

// Precomputed (left << 16) | right — file-level expr breaks AOT global const (#851).
const TYPE_PAIR_NATIVE_LONG_NATIVE_LONG = 65537;
const TYPE_PAIR_NATIVE_DOUBLE_NATIVE_DOUBLE = 196611;
const TYPE_PAIR_NATIVE_LONG_NATIVE_DOUBLE = 65539;
const TYPE_PAIR_NATIVE_DOUBLE_NATIVE_LONG = 196609;
const TYPE_PAIR_NATIVE_LONG_NATIVE_BOOL = 65538;
const TYPE_PAIR_NATIVE_BOOL_NATIVE_LONG = 131073;
const TYPE_PAIR_NATIVE_DOUBLE_NATIVE_BOOL = 196610;
const TYPE_PAIR_NATIVE_BOOL_NATIVE_DOUBLE = 131075;
const TYPE_PAIR_NATIVE_BOOL_NATIVE_BOOL = 131074;
const TYPE_PAIR_STRING_STRING = 8650884;

function type_pair(int $left, int $right): int {
    return ($left << 16) | $right;
}
