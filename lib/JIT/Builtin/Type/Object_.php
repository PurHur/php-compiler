<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCompiler\JIT\Builtin\Refcount;
use PHPCompiler\JIT\Builtin\Type;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM;

class Object_ extends Type {
    public PHPLLVM\Type $pointer;
    private array $classes = [];
    private array $properties = [];
    private array $propNameMap = [];
    /** @var array<int, array<string, int>> class id => method lc => visibility flags */
    private array $methodVisibility = [];
    /** @var array<int, true> class ids with a compiled __construct body */
    private array $hasConstructor = [];
    /** @var array<int, array<string, array{type: int, value: int|float|bool|string|null}>> */
    private array $classConstants = [];

    private ?int $splObjectStorageClassId = null;

    public function register(): void
    {
        $struct = $this->context->context->namedStructType('__object__');
        $this->context->registerType('__object__', $struct);
        $this->context->registerType('__object__*', $struct->pointerType(0));
        $struct->setBody(
            false,
            $this->context->getTypeFromString('__ref__'),
            $this->context->getTypeFromString('int64'),
        );
        $this->context->structFieldMap['__object__'] = [
            'ref' => 0,
            'class_id' => 1,
        ];
        $this->pointer = $this->context->getTypeFromString('__object__*');

        $this->registerFn('__object__load_value_slot', 'void', ['void**', '__value__*']);
        $this->registerFn('__value__readObject', '__object__*', ['__value__*']);
        $this->registerFn('__value__writeObject', 'void', ['__value__*', '__object__*']);
    }

    /**
     * @param list<string> $paramTypes
     */
    private function registerFn(string $name, string $returnType, array $paramTypes): void
    {
        $params = array_map(fn (string $t) => $this->context->getTypeFromString($t), $paramTypes);
        $ft = $this->context->context->functionType(
            $this->context->getTypeFromString($returnType),
            false,
            ...$params
        );
        $fn = $this->context->module->addFunction($name, $ft);
        $fn->addAttributeAtIndex(PHPLLVM\Attribute::INDEX_FUNCTION, $this->context->attributes['alwaysinline']);
        $this->context->registerFunction($name, $fn);
    }

    public function implement(): void
    {
        $this->implementLoadValueSlot();
        $this->implementValueReadObject();
        $this->implementValueWriteObject();
    }

    public function shutdown(): void
    {
    }

    private function implementLoadValueSlot(): void
    {
        $fn = $this->context->lookupFunction('__object__load_value_slot');
        $entry = $fn->appendBasicBlock('entry');
        $nullBlock = $fn->appendBasicBlock('null');
        $loadBlock = $fn->appendBasicBlock('load');
        $done = $fn->appendBasicBlock('done');
        $this->context->builder->positionAtEnd($entry);

        $slot = $fn->getParam(0);
        $dest = $fn->getParam(1);
        $loaded = $this->context->builder->load($slot);
        $voidPtr = $this->context->getTypeFromString('void*');
        $isNull = $this->context->builder->icmp(
            PHPLLVM\Builder::INT_EQ,
            $loaded,
            $voidPtr->constNull()
        );
        $this->context->builder->branchIf($isNull, $nullBlock, $loadBlock);

        $this->context->builder->positionAtEnd($nullBlock);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $dest
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($loadBlock);
        $valPtr = $this->context->builder->pointerCast(
            $loaded,
            $this->context->getTypeFromString('__value__*')
        );
        $this->context->builder->store(
            $this->context->builder->load($valPtr),
            $dest
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
    }

    private function implementValueReadObject(): void
    {
        $fn = $this->context->lookupFunction('__value__readObject');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $value = $fn->getParam(0);
        $map = $this->context->structFieldMap['__value__'];
        $objPtr = $this->context->getTypeFromString('__object__*');
        $typeByte = $this->context->builder->load($this->context->builder->structGep($value, $map['type']));
        $expected = $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_OBJECT, false);
        $isObject = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $typeByte, $expected);
        $ok = $fn->appendBasicBlock('read_obj_ok');
        $empty = $fn->appendBasicBlock('read_obj_empty');
        $merge = $fn->appendBasicBlock('read_obj_merge');
        $this->context->builder->branchIf($isObject, $ok, $empty);
        $this->context->builder->positionAtEnd($ok);
        $ptrField = $this->context->builder->structGep($value, $map['value']);
        $objSlot = $this->context->builder->pointerCast($ptrField, $objPtr->pointerType(0));
        $stored = $this->context->builder->load($objSlot);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($empty);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($merge);
        $result = $this->context->builder->phi($objPtr);
        $result->addIncoming($stored, $ok);
        $result->addIncoming($objPtr->constNull(), $empty);
        $this->context->builder->returnValue($result);
        $this->context->builder->clearInsertionPosition();
    }

    private function implementValueWriteObject(): void
    {
        $fn = $this->context->lookupFunction('__value__writeObject');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $value = $fn->getParam(0);
        $object = $fn->getParam(1);
        $map = $this->context->structFieldMap['__value__'];
        $objPtr = $this->context->getTypeFromString('__object__*');
        $this->context->builder->call(
            $this->context->lookupFunction('__value__valueDelref'),
            $value
        );
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_OBJECT, false),
            $this->context->builder->structGep($value, $map['type'])
        );
        $ptrField = $this->context->builder->structGep($value, $map['value']);
        $objSlot = $this->context->builder->pointerCast($ptrField, $objPtr->pointerType(0));
        $this->context->builder->store($object, $objSlot);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
    }

    public function allocate(int $classId): PHPLLVM\Value
    {
        $objType = $this->context->getTypeFromString('__object__');
        $propCount = count($this->properties[$classId]);
        if (0 === $propCount) {
            $obj = $this->context->memory->malloc($objType);
        } else {
            $obj = $this->context->memory->mallocWithExtra(
                $objType,
                $this->context->constantFromInteger(8 * $propCount, 'size_t')
            );
        }

        $map = $this->context->structFieldMap['__object__'];
        $this->context->builder->store(
            $this->context->constantFromInteger($classId),
            $this->context->builder->structGep($obj, $map['class_id'])
        );

        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_OBJECT | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $obj,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__ref__init'),
            $typeinfo,
            $ref
        );

        if ($propCount > 0) {
            $this->initPropertySlots($obj, $propCount);
        }

        if ($this->isSplObjectStorageClass($classId)) {
            $ht = HashTableHelper::alloc($this->context);
            $voidPtr = $this->context->getTypeFromString('void*');
            $this->context->builder->store(
                $this->context->builder->pointerCast($ht, $voidPtr),
                $this->propertySlotPtr($obj, 0)
            );
        }

        return $obj;
    }

    public function splObjectStorageClassId(): ?int
    {
        return $this->splObjectStorageClassId;
    }

    public function isSplObjectStorageClass(int $classId): bool
    {
        return null !== $this->splObjectStorageClassId && $classId === $this->splObjectStorageClassId;
    }

    /**
     * SplObjectStorage stores entries in a backing __hashtable__ (issue #601).
     */
    public function splBackingHashtable(Variable $obj): Variable
    {
        if (Variable::TYPE_OBJECT !== $obj->type) {
            throw new \LogicException('splBackingHashtable requires __object__*');
        }
        $objPtr = $this->context->helper->loadValue($obj);
        $loaded = $this->context->builder->load($this->propertySlotPtr($objPtr, 0));
        $htPtr = $this->context->builder->pointerCast(
            $loaded,
            $this->context->getTypeFromString('__hashtable__*')
        );

        return new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $htPtr
        );
    }

    private function initPropertySlots(PHPLLVM\Value $obj, int $propCount): void
    {
        $sizeT = $this->context->getTypeFromString('size_t');
        $headerBytes = $this->objectHeaderSize();
        $i8p = $this->context->getTypeFromString('int8*');
        $voidpp = $this->context->getTypeFromString('void**');
        $cast = $this->context->builder->pointerCast($obj, $i8p);
        $nullPtr = $voidpp->getElementType()->constNull();

        for ($slot = 0; $slot < $propCount; ++$slot) {
            $slotOff = $this->context->builder->add(
                $headerBytes,
                $this->context->constantFromInteger(8 * $slot, 'size_t')
            );
            $slotPtr = $this->context->builder->pointerCast(
                $this->context->builder->gep($cast, $slotOff),
                $voidpp
            );
            $this->context->builder->store($nullPtr, $slotPtr);
        }
    }

    private function objectHeaderSize(): PHPLLVM\Value
    {
        return $this->context->builder->ptrToInt(
            $this->context->builder->gep(
                $this->context->getTypeFromString('__object__')->pointerType(0)->constNull(),
                $this->context->context->int32Type()->constInt(1, false)
            ),
            $this->context->getTypeFromString('size_t')
        );
    }

    private function propertySlotPtr(PHPLLVM\Value $obj, int $slotIndex): PHPLLVM\Value
    {
        $i8p = $this->context->getTypeFromString('int8*');
        $voidpp = $this->context->getTypeFromString('void**');
        $cast = $this->context->builder->pointerCast($obj, $i8p);
        $slotOff = $this->context->builder->add(
            $this->objectHeaderSize(),
            $this->context->constantFromInteger(8 * $slotIndex, 'size_t')
        );

        return $this->context->builder->pointerCast(
            $this->context->builder->gep($cast, $slotOff),
            $voidpp
        );
    }

    public function declareClass(Operand $name): int
    {
        if (!$name instanceof Literal) {
            throw new \LogicException('JIT only supports constant named classes');
        }
        $id = count($this->classes);
        $this->properties[$id] = [];
        $this->classConstants[$id] = [];

        return $this->classes[strtolower($name->value)] = $id;
    }

    public function lookupOperand(Operand $name): int
    {
        if (!$name instanceof Literal) {
            throw new \LogicException('JIT only supports constant named classes');
        }

        return $this->lookup($name->value);
    }

    public function lookup(string $name): int
    {
        $lcname = strtolower($name);
        if (!isset($this->classes[$lcname])) {
            $this->registerExternalClass($lcname);
        }

        return $this->classes[$lcname];
    }

    private function registerExternalClass(string $lcname): void
    {
        $id = count($this->classes);
        $this->properties[$id] = [];
        $this->classConstants[$id] = [];
        $this->classes[$lcname] = $id;
        if ('splobjectstorage' === $lcname) {
            $this->splObjectStorageClassId = $id;
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
        }
    }

    /**
     * JIT storage type for properties on vendor CFG / compiler objects (e.g. PHPCfg\Block::$children).
     */
    private function externalPropertyJitType(string $class, string $name): int
    {
        $lcClass = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        $lcName = strtolower($name);

        /** @var array<string, array<string, true>> */
        static $arrayProps = [
            'phpcfg\\block' => [
                'children' => true,
                'parents' => true,
                'phi' => true,
                'hoistedoperands' => true,
                'deadoperands' => true,
            ],
            'phpcompiler\\block' => [
                'blocks' => true,
                'parents' => true,
                'opcodes' => true,
            ],
            'phpcfg\\script' => [
                'functions' => true,
            ],
            'phpcfg\\func' => [
                'params' => true,
            ],
        ];

        if (isset($arrayProps[$lcClass][$lcName])) {
            return Variable::TYPE_HASHTABLE;
        }

        if (
            (str_starts_with($lcClass, 'phpcfg\\') || str_starts_with($lcClass, 'phpcompiler\\'))
            && in_array($lcName, ['children', 'parents', 'args', 'keys', 'values', 'catches', 'params'], true)
        ) {
            return Variable::TYPE_HASHTABLE;
        }

        return Variable::TYPE_VALUE;
    }

    public function defineMethodVisibility(int $classId, string $methodLc, int $visibilityFlags): void
    {
        $this->methodVisibility[$classId][strtolower($methodLc)] = $visibilityFlags;
    }

    public function methodVisibility(int $classId, string $methodLc): int
    {
        return $this->methodVisibility[$classId][strtolower($methodLc)] ?? \PHPCfg\Func::FLAG_PUBLIC;
    }

    public function markHasConstructor(int $classId): void
    {
        $this->hasConstructor[$classId] = true;
    }

    public function hasConstructor(int $classId): bool
    {
        return isset($this->hasConstructor[$classId]);
    }

    public function defineProperty(int $classId, string $name, int $type): void
    {
        if (!isset($this->propNameMap[$name])) {
            $this->propNameMap[$name] = count($this->propNameMap);
        }
        $this->properties[$classId][] = [
            $this->propNameMap[$name], $name, $type, count($this->properties[$classId]),
        ];
    }

    public function defineClassConst(int $classId, string $name, VMVariable $value): void
    {
        $key = strtolower($name);
        $this->classConstants[$classId][$key] = [
            'type' => Variable::fromVMVariable($value->type),
            'value' => $this->compileTimeValueFromVm($value),
        ];
    }

    public function resolveClassId(Operand $classOp): int
    {
        if (!$classOp instanceof Literal) {
            throw new \LogicException('JIT only supports constant named classes for class const fetch');
        }
        $name = strtolower($classOp->value);
        if ('self' === $name || 'static' === $name) {
            if (null === $this->context->scope->className) {
                throw new \LogicException('self:: used outside of class scope');
            }

            return $this->lookup($this->context->scope->className);
        }

        return $this->lookup($classOp->value);
    }

    public function classConstFetch(int $classId, string $constName): Variable
    {
        $key = strtolower($constName);
        if (!isset($this->classConstants[$classId][$key])) {
            throw new \LogicException("Undefined class constant: {$constName}");
        }

        return $this->jitConstantFromEntry($this->classConstants[$classId][$key]);
    }

    public function emitInstanceOf(Variable $expr, string $className): Variable
    {
        $expectedId = $this->lookup($className);
        $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
        $objMap = $this->context->structFieldMap['__object__'];
        $expectedClassId = $this->context->constantFromInteger($expectedId);

        if (Variable::TYPE_OBJECT === $expr->type) {
            $obj = $this->context->helper->loadValue($expr);
            $classId = $this->context->builder->load(
                $this->context->builder->structGep($obj, $objMap['class_id'])
            );
            $match = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $classId,
                $expectedClassId
            );

            return new Variable(
                $this->context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $match
            );
        }

        if (Variable::TYPE_VALUE === $expr->type) {
            $valuePtr = Variable::KIND_VARIABLE === $expr->kind
                ? $expr->value
                : $this->context->helper->loadValue($expr);
            $obj = $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valuePtr
            );
            $objType = $this->context->getTypeFromString('__object__*');
            $isObject = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_NE,
                $obj,
                $objType->constNull()
            );
            $classId = $this->context->builder->load(
                $this->context->builder->structGep($obj, $objMap['class_id'])
            );
            $matches = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $classId,
                $expectedClassId
            );
            $match = $this->context->builder->and($isObject, $matches);

            return new Variable(
                $this->context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $match
            );
        }

        return new Variable(
            $this->context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $falseVal
        );
    }

    /**
     * @param array{type: int, value: int|float|bool|string|null} $entry
     */
    private function jitConstantFromEntry(array $entry): Variable
    {
        switch ($entry['type']) {
            case Variable::TYPE_NATIVE_LONG:
                return Variable::fromConstantInt($this->context, (int) $entry['value']);
            case Variable::TYPE_NATIVE_DOUBLE:
                $lit = new Literal($entry['value']);
                $lit->type = \PHPTypes\Type::float();

                return Variable::fromLiteral($this->context, $lit);
            case Variable::TYPE_NATIVE_BOOL:
                $lit = new Literal($entry['value']);
                $lit->type = \PHPTypes\Type::bool();

                return Variable::fromLiteral($this->context, $lit);
            case Variable::TYPE_STRING:
                $lit = new Literal($entry['value']);
                $lit->type = \PHPTypes\Type::string();

                return Variable::fromLiteral($this->context, $lit);
            case Variable::TYPE_NULL:
                $slot = JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($this->context, $slot)
                );

                return new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
            default:
                throw new \LogicException('Unsupported class constant type for JIT');
        }
    }

    /**
     * @return int|float|bool|string|null
     */
    private function compileTimeValueFromVm(VMVariable $value): int|float|bool|string|null
    {
        switch ($value->type) {
            case VMVariable::TYPE_NULL:
                return null;
            case VMVariable::TYPE_INTEGER:
                return $value->toInt();
            case VMVariable::TYPE_FLOAT:
                return $value->toFloat();
            case VMVariable::TYPE_BOOLEAN:
                return $value->toBool();
            case VMVariable::TYPE_STRING:
                return $value->toString();
            default:
                throw new \LogicException('Class constant value must be a scalar compile-time constant');
        }
    }

    public function propertyFetch(PHPLLVM\Value $obj, string $class, string $name): Variable
    {
        $classId = $this->lookup('' !== $class ? $class : 'stdclass');
        $nameId = $this->propNameMap[$name] ?? null;
        $hasProp = false;
        if (null !== $nameId) {
            foreach ($this->properties[$classId] as $propset) {
                if ($propset[0] === $nameId) {
                    $hasProp = true;
                    break;
                }
            }
        }
        if (!$hasProp) {
            $this->defineProperty($classId, $name, $this->externalPropertyJitType($class, $name));
            $nameId = $this->propNameMap[$name];
        }
        foreach ($this->properties[$classId] as $propset) {
            if ($propset[0] === $nameId) {
                $slot = $this->propertySlotPtr($obj, $propset[3]);
                $loaded = $this->context->builder->load($slot);
                if (Variable::TYPE_VALUE === $propset[2]) {
                    $valueType = $this->context->getTypeFromString('__value__');
                    $storage = $this->context->builder->alloca($valueType, 1, 'prop_'.$name);
                    $valueMap = $this->context->structFieldMap['__value__'];
                    $this->context->builder->store(
                        $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
                        $this->context->builder->structGep($storage, $valueMap['type'])
                    );
                    $this->context->builder->call(
                        $this->context->lookupFunction('__object__load_value_slot'),
                        $slot,
                        $storage
                    );
                    $var = new Variable(
                        $this->context,
                        $propset[2],
                        Variable::KIND_VARIABLE,
                        $storage,
                    );
                    $var->objectPropertySlot = $slot;
                    $var->objectPropertyType = $propset[2];

                    return $var;
                }
                if (Variable::TYPE_HASHTABLE === $propset[2]) {
                    $htPtr = $this->context->builder->pointerCast(
                        $loaded,
                        $this->context->getTypeFromString('__hashtable__*')
                    );
                    $var = new Variable(
                        $this->context,
                        Variable::TYPE_HASHTABLE,
                        Variable::KIND_VALUE,
                        $htPtr
                    );
                    $var->objectPropertySlot = $slot;
                    $var->objectPropertyType = $propset[2];

                    return $var;
                }
                $llvmType = Variable::getStringType($propset[2]);
                $typed = $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString($llvmType)
                );
                $var = new Variable(
                    $this->context,
                    $propset[2],
                    Variable::KIND_VALUE,
                    $typed,
                );
                $var->objectPropertySlot = $slot;
                $var->objectPropertyType = $propset[2];

                return $var;
            }
        }
        throw new \LogicException("Could not find property $name for class $classId");
    }

    /**
     * Persist assignment to an instance property slot (void** on the object, issue #58).
     */
    public function propertyStore(PHPLLVM\Value $slot, Variable $value, int $propertyType): void
    {
        $voidPtr = $this->context->getTypeFromString('void*');

        if (Variable::TYPE_HASHTABLE === $propertyType) {
            if (0 !== ($value->type & Variable::IS_NATIVE_ARRAY)) {
                $ht = HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                $stored = $this->context->builder->pointerCast($ht, $voidPtr);
                $this->context->builder->store($stored, $slot);
                $this->context->refcount->addref($ht);

                return;
            }
            if (Variable::TYPE_HASHTABLE === $value->type) {
                $stored = $this->context->builder->pointerCast(
                    $this->context->helper->loadValue($value),
                    $voidPtr
                );
                $this->context->builder->store($stored, $slot);
                $value->addref();

                return;
            }
            if (Variable::TYPE_VALUE === $value->type) {
                $valuePtr = Variable::KIND_VARIABLE === $value->kind
                    ? JitValueBox::pointer($this->context, $value->value)
                    : $value->value;
                $ht = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readHashtable'),
                    $valuePtr
                );
                $stored = $this->context->builder->pointerCast($ht, $voidPtr);
                $this->context->builder->store($stored, $slot);
                $this->context->refcount->addref($ht);
                $value->addref();

                return;
            }
        }

        $valueType = $this->context->getTypeFromString('__value__');
        $heapVal = $this->context->memory->malloc($valueType);
        $heapPtr = $this->context->builder->pointerCast(
            $heapVal,
            $this->context->getTypeFromString('__value__*')
        );
        $valueMap = $this->context->structFieldMap['__value__'];
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $this->context->builder->structGep($heapVal, $valueMap['type'])
        );

        if (Variable::TYPE_VALUE === $value->type) {
            $valuePtr = Variable::KIND_VARIABLE === $value->kind
                ? JitValueBox::pointer($this->context, $value->value)
                : $value->value;
            JitValueBox::copyFromPointer($this->context, $heapVal, $valuePtr);
            $value->addref();

            $this->context->builder->store(
                $this->context->builder->pointerCast($heapPtr, $voidPtr),
                $slot
            );

            return;
        }

        if (Variable::TYPE_STRING === $value->type) {
            $str = $this->context->helper->loadValue($value);
            $owned = $this->context->builder->call(
                $this->context->lookupFunction('__string__separate'),
                $str
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                $heapPtr,
                $owned
            );
            $value->addref();
            $this->context->builder->store(
                $this->context->builder->pointerCast($heapPtr, $voidPtr),
                $slot
            );

            return;
        }

        if (Variable::TYPE_OBJECT === $value->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeObject'),
                $heapPtr,
                $this->context->helper->loadValue($value)
            );
            $value->addref();
            $this->context->builder->store(
                $this->context->builder->pointerCast($heapPtr, $voidPtr),
                $slot
            );

            return;
        }

        if (Variable::TYPE_VALUE === $propertyType) {
            if (0 !== ($value->type & Variable::IS_NATIVE_ARRAY)) {
                $ht = HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    $heapPtr,
                    $ht
                );
                $this->context->refcount->addref($ht);
                $this->context->builder->store(
                    $this->context->builder->pointerCast($heapPtr, $voidPtr),
                    $slot
                );

                return;
            }
            if (Variable::TYPE_HASHTABLE === $value->type) {
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    $heapPtr,
                    $this->context->helper->loadValue($value)
                );
                $value->addref();
                $this->context->builder->store(
                    $this->context->builder->pointerCast($heapPtr, $voidPtr),
                    $slot
                );

                return;
            }
        }

        if (Variable::TYPE_NATIVE_LONG === $propertyType && Variable::TYPE_NATIVE_LONG === $value->type) {
            $nativeType = $this->context->getTypeFromString('int64');
            $nativePtr = $this->context->memory->malloc($nativeType);
            $this->context->builder->store($this->context->helper->loadValue($value), $nativePtr);
            $this->context->builder->store(
                $this->context->builder->pointerCast($nativePtr, $voidPtr),
                $slot
            );

            return;
        }

        if (Variable::TYPE_NATIVE_BOOL === $propertyType && Variable::TYPE_NATIVE_BOOL === $value->type) {
            $nativeType = $this->context->getTypeFromString('int1');
            $nativePtr = $this->context->memory->malloc($nativeType);
            $this->context->builder->store($this->context->helper->loadValue($value), $nativePtr);
            $this->context->builder->store(
                $this->context->builder->pointerCast($nativePtr, $voidPtr),
                $slot
            );

            return;
        }

        throw new \LogicException(
            'Unsupported property store from '
            .Variable::getStringType($value->type)
            .' to '
            .Variable::getStringType($propertyType)
        );
    }
}
