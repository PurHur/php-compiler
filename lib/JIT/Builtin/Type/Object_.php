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
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Refcount;
use PHPCompiler\JIT\Builtin\Type;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM;

class Object_ extends Type {
    public PHPLLVM\Type $pointer;
    private array $classes = [];
    /** @var array<string, true> lowercase enum name => registered (#1373, #1356) */
    private array $enums = [];
    /** @var array<int, string> class id => canonical name */
    private array $classIdToName = [];
    /** @var array<string, string> declaring class lc => parent class lc (#1858) */
    private array $classParentLc = [];
    /** @var array<string, list<string>> class lc => interface lc names (#1357, #3077) */
    private array $classInterfacesLc = [];
    /** @var array<string, list<string>> interface lc => parent interface lc names */
    private array $interfaceExtendsLc = [];
    /** @var array<string, true> interface lc => registered */
    private array $interfaceClassLcs = [];
    /** @var array<string, list<string>> class lc => transitive interface lc (lazy) */
    private array $classAllInterfacesLc = [];
    private array $properties = [];
    private array $propNameMap = [];
    /** @var array<int, array<string, int>> class id => method lc => visibility flags */
    private array $methodVisibility = [];
    /** @var array<int, true> class ids with a compiled __construct body */
    private array $hasConstructor = [];
    /** @var array<int, true> vendor/external classes without lowered methods (#2666) */
    private array $externalOnlyClassIds = [];
    /** @var array<int, array<string, array{type: int, value: int|float|bool|string|null}>> */
    private array $classConstants = [];
    /** @var array<int, array<int, array{propertyType: int, type: int, value: int|float|bool|string|null}>> */
    private array $propertyDefaults = [];
    /**
     * @var array<int, array<string, array{type: int, global: \PHPLLVM\Value}>>
     *     class id => property lc => typed LLVM global
     */
    private array $staticPropertyGlobals = [];

    private ?int $splObjectStorageClassId = null;

    /** @var array<int, true> class ids declared readonly (issue #1360) */
    private array $readonlyClassIds = [];

    /** @var array<int, PHPLLVM\Value> property slot handle => owning __object__* */
    private array $slotReceivers = [];

    public function register(): void
    {
        $struct = $this->context->context->namedStructType('__object__');
        $this->context->registerType('__object__', $struct);
        $this->context->registerType('__object__*', $struct->pointerType(0));
        $struct->setBody(
            false,
            $this->context->getTypeFromString('__ref__'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('int8'),
        );
        $this->context->structFieldMap['__object__'] = [
            'ref' => 0,
            'class_id' => 1,
            'constructed' => 2,
        ];
        $this->pointer = $this->context->getTypeFromString('__object__*');
        \PHPCompiler\JIT\Builtin\ReadonlyRaise::registerDeclarations($this->context);
        \PHPCompiler\JIT\Builtin\ReadonlyRaise::ensureLinked($this->context);
        \PHPCompiler\JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
        \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
        // JitThrow linked on demand when compiling try/catch (#1056).

        $this->registerFn('__object__load_value_slot', 'void', ['void**', '__value__*']);
        $this->registerFn('__value__readObject', '__object__*', ['__value__*']);
        $this->registerFn('__value__writeObject', 'void', ['__value__*', '__object__*']);
    }

    /**
     * @param list<string> $paramTypes
     */
    private function registerFn(string $name, string $returnType, array $paramTypes): void
    {
        $params = [];
        foreach ($paramTypes as $t) {
            $params[] = $this->context->getTypeFromString($t);
        }
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
            $this->context->constantFromInteger($classId, 'int64'),
            $this->context->builder->structGep($obj, $map['class_id'])
        );
        $constructedInit = $this->hasConstructor($classId) ? 0 : 1;
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt($constructedInit, false),
            $this->context->builder->structGep($obj, $map['constructed'])
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
            $this->initPropertyDefaults($obj, $classId);
            $this->initEmptyHashtableProperties($obj, $classId);
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

    /**
     * Object shell for M3 emit-helper TU — slots + defaults/ht, no vendor ctor (#2540, #2550).
     */
    public function allocateEmitTuShell(int $classId): PHPLLVM\Value
    {
        $objType = $this->context->getTypeFromString('__object__');
        $propCount = count($this->properties[$classId] ?? []);
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
            $this->context->constantFromInteger($classId, 'int64'),
            $this->context->builder->structGep($obj, $map['class_id'])
        );
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(1, false),
            $this->context->builder->structGep($obj, $map['constructed'])
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
            $this->initPropertyDefaults($obj, $classId);
            $this->initEmptyHashtableProperties($obj, $classId);
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

    private function initPropertyDefaults(PHPLLVM\Value $obj, int $classId): void
    {
        if (!isset($this->propertyDefaults[$classId])) {
            return;
        }
        foreach ($this->propertyDefaults[$classId] as $slotIndex => $entry) {
            $slot = $this->propertySlotPtr($obj, $slotIndex);
            $var = $this->jitConstantFromEntry([
                'type' => $entry['type'],
                'value' => $entry['value'],
            ]);
            $this->propertyStore($slot, $var, $entry['propertyType']);
        }
    }

    private function initEmptyHashtableProperties(PHPLLVM\Value $obj, int $classId): void
    {
        if ($this->isSplObjectStorageClass($classId)) {
            return;
        }
        if (!isset($this->properties[$classId])) {
            return;
        }
        $initialized = $this->propertyDefaults[$classId] ?? [];
        foreach ($this->properties[$classId] as $propset) {
            if (Variable::TYPE_HASHTABLE !== $propset[2]) {
                continue;
            }
            if (isset($initialized[$propset[3]])) {
                continue;
            }
            $slot = $this->propertySlotPtr($obj, $propset[3]);
            $loaded = $this->context->builder->load($slot);
            $nullPtr = $loaded->typeOf()->getElementType()->constNull();
            $isEmpty = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $loaded,
                $nullPtr
            );
            $fn = $this->context->builder->getInsertBlock()->getParent();
            assert($fn instanceof PHPLLVM\Value\Function_);
            $initBlock = $fn->appendBasicBlock('prop_ht_init_'.$classId.'_'.$propset[3]);
            $doneBlock = $fn->appendBasicBlock('prop_ht_done_'.$classId.'_'.$propset[3]);
            $this->context->builder->branchIf($isEmpty, $initBlock, $doneBlock);
            $this->context->builder->positionAtEnd($initBlock);
            $ht = HashTableHelper::alloc($this->context);
            $voidPtr = $this->context->getTypeFromString('void*');
            $this->context->builder->store(
                $this->context->builder->pointerCast($ht, $voidPtr),
                $slot
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($doneBlock);
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
        $lc = strtolower($name->value);
        if (isset($this->classes[$lc])) {
            unset($this->externalOnlyClassIds[$this->classes[$lc]]);

            return $this->classes[$lc];
        }
        $id = count($this->classes);
        $this->properties[$id] = [];
        $this->classConstants[$id] = [];
        $this->staticPropertyGlobals[$id] = [];

        $this->classIdToName[$id] = $name->value;

        return $this->classes[strtolower($name->value)] = $id;
    }

    public function setClassReadonly(int $classId, bool $readonly): void
    {
        if ($readonly) {
            $this->readonlyClassIds[$classId] = true;
        } else {
            unset($this->readonlyClassIds[$classId]);
        }
    }

    public function inheritReadonlyFromParent(int $childId, string $parentLc): void
    {
        $parentLc = strtolower(ltrim($parentLc, '\\'));
        if (!isset($this->classes[$parentLc])) {
            return;
        }
        $parentId = $this->classes[$parentLc];
        if (isset($this->readonlyClassIds[$parentId])) {
            $this->readonlyClassIds[$childId] = true;
        }
    }

    public function hasReadonlyClasses(): bool
    {
        return [] !== $this->readonlyClassIds;
    }

    /**
     * @return list<int>
     */
    public function readonlyClassIds(): array
    {
        return array_keys($this->readonlyClassIds);
    }

    public function markObjectConstructed(PHPLLVM\Value $obj): void
    {
        $map = $this->context->structFieldMap['__object__'];
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(1, false),
            $this->context->builder->structGep($obj, $map['constructed'])
        );
    }

    public function receiverForPropertySlot(PHPLLVM\Value $slot): ?PHPLLVM\Value
    {
        return $this->slotReceivers[spl_object_id($slot)] ?? null;
    }

    public function setClassParentName(string $className, string $parentName): void
    {
        $childLc = strtolower(ltrim($className, '\\'));
        $parentLc = strtolower(ltrim($parentName, '\\'));
        if (!isset($this->classes[$parentLc])) {
            // Allow forward-declared inheritance (parent declared later in the same script/bundle).
            // If the parent is never declared, we still treat it as an external stub so compilation can proceed.
            $this->registerExternalClass($parentLc, $parentName);
        }
        $this->classParentLc[$childLc] = $parentLc;
    }

    public function parentClassLc(string $declaringClassLc): ?string
    {
        return $this->classParentLc[strtolower(ltrim($declaringClassLc, '\\'))] ?? null;
    }

    /**
     * Compile-time extends-chain check for is_a() string form (#3478).
     */
    public function classIsInstanceOf(string $childName, string $parentName): bool
    {
        return $this->classEntryIsInstanceOfLc(
            strtolower(ltrim($childName, '\\')),
            strtolower(ltrim($parentName, '\\'))
        );
    }

    /**
     * Compile-time strict-subclass check for is_subclass_of() string form (#3478).
     */
    public function classIsSubclassOf(string $childName, string $parentName): bool
    {
        $childLc = strtolower(ltrim($childName, '\\'));
        $parentLc = strtolower(ltrim($parentName, '\\'));
        if ($childLc === $parentLc) {
            return false;
        }

        return $this->classEntryIsInstanceOfLc($childLc, $parentLc);
    }

    /**
     * @return list<int> class ids whose instances satisfy instanceof $className
     */
    public function classIdsInstanceOf(string $className): array
    {
        $wantLc = strtolower(ltrim($className, '\\'));
        $ids = [];
        foreach ($this->classIdToName as $id => $name) {
            if ($this->classEntryIsInstanceOfLc(strtolower(ltrim($name, '\\')), $wantLc)) {
                $ids[] = $id;
            }
        }
        if (isset($this->classes[$wantLc])) {
            $expectedId = $this->classes[$wantLc];
            if (!in_array($expectedId, $ids, true)) {
                $ids[] = $expectedId;
            }
        }

        return $ids;
    }

    private function classEntryIsInstanceOfLc(string $classLc, string $wantLc): bool
    {
        $visited = [];
        $current = $classLc;
        while (true) {
            if (isset($visited[$current])) {
                return false;
            }
            $visited[$current] = true;
            if ($current === $wantLc) {
                return true;
            }
            $parent = $this->classParentLc[$current] ?? null;
            if (null === $parent) {
                return false;
            }
            $current = $parent;
        }
    }

    /**
     * @param list<string> $interfaceLcs lowercase interface names
     */
    public function setClassInterfaces(string $className, array $interfaceLcs): void
    {
        $lc = strtolower(ltrim($className, '\\'));
        $expanded = [];
        foreach ($interfaceLcs as $iface) {
            $expanded = array_merge($expanded, $this->expandInterfaceLc($iface));
        }
        $this->classInterfacesLc[$lc] = array_values(array_unique($expanded));
        unset($this->classAllInterfacesLc[$lc]);
    }

    /**
     * @param list<string> $extendsLcs lowercase parent interface names
     */
    public function setInterfaceExtends(string $interfaceName, array $extendsLcs): void
    {
        $lc = strtolower(ltrim($interfaceName, '\\'));
        $this->interfaceClassLcs[$lc] = true;
        $this->interfaceExtendsLc[$lc] = array_values(array_unique(array_map(
            static fn (string $n): string => strtolower(ltrim($n, '\\')),
            $extendsLcs
        )));
        $this->classInterfacesLc[$lc] = $this->expandInterfaceLc($lc);
        unset($this->classAllInterfacesLc[$lc]);
    }

    public function isInterfaceClassLc(string $classLc): bool
    {
        return isset($this->interfaceClassLcs[strtolower(ltrim($classLc, '\\'))]);
    }

    public function markInterfaceClass(string $interfaceName): void
    {
        $lc = strtolower(ltrim($interfaceName, '\\'));
        $this->interfaceClassLcs[$lc] = true;
        if (!isset($this->classInterfacesLc[$lc])) {
            $this->classInterfacesLc[$lc] = [$lc];
        }
    }

    /**
     * @return list<string>
     */
    public function allInterfacesForClassLc(string $classLc): array
    {
        $classLc = strtolower(ltrim($classLc, '\\'));
        if (isset($this->classAllInterfacesLc[$classLc])) {
            return $this->classAllInterfacesLc[$classLc];
        }
        $ifaces = $this->classInterfacesLc[$classLc] ?? [];
        $parent = $this->classParentLc[$classLc] ?? null;
        if (null !== $parent) {
            $ifaces = array_merge($ifaces, $this->allInterfacesForClassLc($parent));
        }

        return $this->classAllInterfacesLc[$classLc] = array_values(array_unique($ifaces));
    }

    /**
     * @return list<string>
     */
    private function expandInterfaceLc(string $ifaceLc): array
    {
        $ifaceLc = strtolower(ltrim($ifaceLc, '\\'));
        $out = [$ifaceLc];
        foreach ($this->interfaceExtendsLc[$ifaceLc] ?? [] as $parent) {
            $out = array_merge($out, $this->expandInterfaceLc($parent));
        }

        return array_values(array_unique($out));
    }

    public function classLcForId(int $classId): ?string
    {
        $name = $this->classIdToName[$classId] ?? null;
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    public function declareEnum(Operand $name): int
    {
        $this->enums[strtolower($name->value)] = true;

        return $this->declareClass($name);
    }

    public function hasDeclaredClass(string $name): bool
    {
        return isset($this->classes[strtolower($name)]);
    }

    /** User-defined classes from DECLARE_CLASS (not JIT external stubs). */
    public function hasUserDeclaredClass(string $name): bool
    {
        $lc = strtolower($name);
        if (isset($this->enums[$lc])) {
            return false;
        }
        if (!isset($this->classes[$lc])) {
            return false;
        }

        return isset($this->classIdToName[$this->classes[$lc]]);
    }

    /**
     * @return array<int, string>
     */
    public function allClassNamesById(): array
    {
        return $this->classIdToName;
    }

    /**
     * Lowercase registry keys for JIT class_exists() runtime compare (#1214, #1056).
     *
     * @return list<string>
     */
    public function allDeclaredClassLowerNames(): array
    {
        return array_keys($this->classes);
    }

    public function hasUserDeclaredEnum(string $name): bool
    {
        return isset($this->enums[strtolower($name)]);
    }

    /**
     * Canonical enum class names from DECLARE_ENUM (issue #3538).
     *
     * @return list<string>
     */
    public function allDeclaredEnumNames(): array
    {
        $names = [];
        foreach (array_keys($this->enums) as $enumLc) {
            $resolved = null;
            foreach ($this->classIdToName as $name) {
                if (strtolower(ltrim($name, '\\')) === $enumLc) {
                    $resolved = $name;
                    break;
                }
            }
            $names[] = $resolved ?? $enumLc;
        }

        return $names;
    }

    /**
     * Lowercase registry keys for JIT enum_exists() runtime compare (#1373).
     *
     * @return list<string>
     */
    public function allDeclaredEnumLowerNames(): array
    {
        return array_keys($this->enums);
    }

    /**
     * Shallow clone: allocate a new object with the same class and copy property slots.
     */
    public function cloneObject(PHPLLVM\Value $src): PHPLLVM\Value
    {
        $classIds = array_keys($this->classIdToName);
        if (1 === count($classIds)) {
            $id = $classIds[0];
            $dest = $this->allocate($id);
            if (isset($this->properties[$id]) && [] !== $this->properties[$id]) {
                $fn = $this->context->builder->getInsertBlock()->getParent();
                assert($fn instanceof PHPLLVM\Value\Function_);
                $afterProps = $fn->appendBasicBlock('clone_single_props_done');
                $this->copyPropertySlots($dest, $src, $id, $afterProps);
                $this->context->builder->positionAtEnd($afterProps);
            }

            return $dest;
        }

        $objMap = $this->context->structFieldMap['__object__'];
        $classId = $this->context->builder->load(
            $this->context->builder->structGep($src, $objMap['class_id'])
        );
        $fn = $this->context->builder->getInsertBlock()->getParent();
        assert($fn instanceof PHPLLVM\Value\Function_);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('clone_done');
        $exit = $fn->appendBasicBlock('clone_exit');
        $incomings = [];
        $classIds = array_keys($this->classIdToName);
        if ([] === $classIds) {
            $nullObj = $this->pointer->constNull();
            $this->context->builder->branch($done);
            $incomings[] = [$nullObj, $entry];
            $this->context->builder->positionAtEnd($done);
            $result = $this->context->builder->phi($this->pointer);
            foreach ($incomings as [$value, $block]) {
                $result->addIncoming($value, $block);
            }
            $this->context->builder->branch($exit);
            $this->context->builder->positionAtEnd($exit);

            return $result;
        }
        $fallback = $fn->appendBasicBlock('clone_unknown');
        $caseBlocks = [];
        foreach ($classIds as $id) {
            $caseBlocks[] = $fn->appendBasicBlock('clone_class_'.$id);
        }
        $checkBlock = $entry;
        foreach ($classIds as $i => $id) {
            $this->context->builder->positionAtEnd($checkBlock);
            $expected = $this->context->constantFromInteger($id, 'int64');
            $isId = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classId, $expected);
            $nextCheck = $i + 1 < count($classIds)
                ? $fn->appendBasicBlock('clone_try_'.($i + 1))
                : $fallback;
            $this->context->builder->branchIf($isId, $caseBlocks[$i], $nextCheck);
            $this->context->builder->positionAtEnd($caseBlocks[$i]);
            $dest = $this->allocate($id);
            $afterProps = $fn->appendBasicBlock('clone_class_'.$id.'_props_done');
            $this->copyPropertySlots($dest, $src, $id, $afterProps);
            $this->context->builder->positionAtEnd($afterProps);
            $this->context->builder->branch($done);
            $incomings[] = [$dest, $afterProps];
            $checkBlock = $nextCheck;
        }
        $this->context->builder->positionAtEnd($fallback);
        $nullObj = $this->pointer->constNull();
        $this->context->builder->branch($done);
        $incomings[] = [$nullObj, $fallback];
        $this->context->builder->positionAtEnd($done);
        $result = $this->context->builder->phi($this->pointer);
        foreach ($incomings as [$value, $block]) {
            $result->addIncoming($value, $block);
        }
        $this->context->builder->branch($exit);
        $this->context->builder->positionAtEnd($exit);

        return $result;
    }

    private function copyPropertySlots(
        PHPLLVM\Value $dest,
        PHPLLVM\Value $src,
        int $classId,
        PHPLLVM\LLVMAbstract\BasicBlock $continue
    ): void {
        if (!isset($this->properties[$classId]) || [] === $this->properties[$classId]) {
            $this->copyConstructedFlag($dest, $src);
            $this->context->builder->branch($continue);

            return;
        }
        $className = $this->classNameForId($classId);
        foreach ($this->properties[$classId] as $propset) {
            $propName = $propset[1];
            $propType = $propset[2];
            $slotIndex = $propset[3];
            $value = $this->propertyFetch($src, $className, $propName);
            $this->propertyStore($this->propertySlotPtr($dest, $slotIndex), $value, $propType);
        }
        $this->copyConstructedFlag($dest, $src);
        $this->context->builder->branch($continue);
    }

    /** Preserve post-construct state on clone (Zend zend_clones.c; issue #3430). */
    private function copyConstructedFlag(PHPLLVM\Value $dest, PHPLLVM\Value $src): void
    {
        $map = $this->context->structFieldMap['__object__'];
        $constructed = $this->context->builder->load(
            $this->context->builder->structGep($src, $map['constructed'])
        );
        $this->context->builder->store(
            $constructed,
            $this->context->builder->structGep($dest, $map['constructed'])
        );
    }

    public function classNameForId(int $id): string
    {
        if (!isset($this->classIdToName[$id])) {
            throw new \LogicException("Unknown class id {$id}");
        }

        return $this->classIdToName[$id];
    }

    public function hasMethod(int $classId, string $methodLc): bool
    {
        return isset($this->methodVisibility[$classId][strtolower($methodLc)]);
    }

    public function hasProperty(int $classId, string $property): bool
    {
        $lc = strtolower($property);
        if (isset($this->staticPropertyGlobals[$classId][$lc])) {
            return true;
        }
        foreach ($this->properties[$classId] ?? [] as $propset) {
            if (strtolower($propset[1]) === $lc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declared instance and static property names for a class (issue #1372).
     *
     * @return list<string>
     */
    public function declaredPropertyNames(int $classId): array
    {
        $names = [];
        foreach ($this->properties[$classId] ?? [] as $propset) {
            $names[] = $propset[1];
        }
        foreach (array_keys($this->staticPropertyGlobals[$classId] ?? []) as $key) {
            $names[] = $key;
        }

        return $names;
    }

    /**
     * Declared instance property metadata for a class (issue #1370).
     *
     * @return list<array{0: int, 1: string, 2: int, 3: int}>
     */
    public function instancePropertySets(int $classId): array
    {
        return $this->properties[$classId] ?? [];
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
            $this->registerExternalClass($lcname, $name);
        } else {
            $this->ensureExternalClassConstants($this->classes[$lcname], $lcname);
        }

        return $this->classes[$lcname];
    }

    private function ensureExternalClassConstants(int $id, string $lcname): void
    {
        if ('phpcompiler\\vm\\variable' === $lcname) {
            $this->seedExternalClassConstants($id, [
                'type_undefined' => \PHPCompiler\VM\Variable::TYPE_UNDEFINED,
                'type_null' => \PHPCompiler\VM\Variable::TYPE_NULL,
                'type_integer' => \PHPCompiler\VM\Variable::TYPE_INTEGER,
                'type_float' => \PHPCompiler\VM\Variable::TYPE_FLOAT,
                'type_boolean' => \PHPCompiler\VM\Variable::TYPE_BOOLEAN,
                'type_string' => \PHPCompiler\VM\Variable::TYPE_STRING,
                'type_array' => \PHPCompiler\VM\Variable::TYPE_ARRAY,
                'type_object' => \PHPCompiler\VM\Variable::TYPE_OBJECT,
                'type_indirect' => \PHPCompiler\VM\Variable::TYPE_INDIRECT,
                'type_string_offset' => \PHPCompiler\VM\Variable::TYPE_STRING_OFFSET,
            ]);
        }
        if ('phpcompiler\\jit\\variable' === $lcname || 'variable' === $lcname) {
            $this->seedExternalClassConstants($id, [
                'type_null' => \PHPCompiler\JIT\Variable::TYPE_NULL,
                'type_native_long' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_LONG,
                'type_native_bool' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_BOOL,
                'type_native_double' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_DOUBLE,
                'type_string' => \PHPCompiler\JIT\Variable::TYPE_STRING,
                'type_object' => \PHPCompiler\JIT\Variable::TYPE_OBJECT,
                'type_value' => \PHPCompiler\JIT\Variable::TYPE_VALUE,
                'type_hashtable' => \PHPCompiler\JIT\Variable::TYPE_HASHTABLE,
                'is_native_array' => \PHPCompiler\JIT\Variable::IS_NATIVE_ARRAY,
                'is_refcounted' => \PHPCompiler\JIT\Variable::IS_REFCOUNTED,
                'kind_variable' => \PHPCompiler\JIT\Variable::KIND_VARIABLE,
                'kind_value' => \PHPCompiler\JIT\Variable::KIND_VALUE,
            ]);
        }
        if ('phptypes\\type' === $lcname || 'type' === $lcname) {
            $this->seedExternalClassConstants($id, ['type_null'=>\PHPTypes\Type::TYPE_NULL,'type_boolean'=>\PHPTypes\Type::TYPE_BOOLEAN,'type_long'=>\PHPTypes\Type::TYPE_LONG,'type_double'=>\PHPTypes\Type::TYPE_DOUBLE,'type_string'=>\PHPTypes\Type::TYPE_STRING,'type_object'=>\PHPTypes\Type::TYPE_OBJECT,'type_array'=>\PHPTypes\Type::TYPE_ARRAY,'type_callable'=>\PHPTypes\Type::TYPE_CALLABLE,'type_union'=>\PHPTypes\Type::TYPE_UNION,'type_intersection'=>\PHPTypes\Type::TYPE_INTERSECTION]);
        }
        if ('phpcompiler\\runtime' === $lcname || 'runtime' === $lcname) {
            $this->seedExternalClassConstants($id, [
                'mode_normal' => \PHPCompiler\Runtime::MODE_NORMAL,
                'mode_aot' => \PHPCompiler\Runtime::MODE_AOT,
            ]);
        }
    }

    private function seedExternalClassConstants(int $id, array $constants): void
    {
        foreach ($constants as $name => $value) {
            if (!isset($this->classConstants[$id][$name])) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
    }

    public function isExternalOnlyClass(int $classId): bool
    {
        return isset($this->externalOnlyClassIds[$classId]);
    }

    private function registerExternalClass(string $lcname, string $displayName): void
    {
        $id = count($this->classes);
        $this->externalOnlyClassIds[$id] = true;
        $this->properties[$id] = [];
        $this->classConstants[$id] = [];
        $this->classIdToName[$id] = $displayName;
        $this->classes[$lcname] = $id;
        // propertyFetch / copyProperties use classNameForId; declareClass sets this, externals must too (#1514, #1056).
        $this->classIdToName[$id] = $lcname;
        $this->ensureExternalClassConstants($id, $lcname);
        $this->seedExternalClassProperties($id, $lcname);
        if ('reflectionattribute' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
        }
        if ('reflectionclass' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
        }
        if ('reflectionmethod' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
            $this->defineProperty($id, 'method', Variable::TYPE_STRING);
        }
        if ('phpcompiler\vm\context' === $lcname) {
            $this->defineProperty($id, 'runtime', Variable::TYPE_OBJECT);
            $this->defineProperty($id, 'errors', Variable::TYPE_OBJECT);
            $this->defineProperty($id, 'scriptStack', Variable::TYPE_OBJECT);
        }
        if ('phpcompiler\runtime' === $lcname) {
            $selfHostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
            if ('1' === $selfHostAot || 'true' === strtolower((string) $selfHostAot)) {
                // Full Runtime property init segfaults LLVM 9 when lowering `new Runtime()` (#2600).
                $this->defineProperty($id, 'mode', Variable::TYPE_NATIVE_LONG);
            } else {
                foreach (
                    [
                        'compiler',
                        'parser',
                        'preprocessor',
                        'postprocessor',
                        'detector',
                        'assignOpResolver',
                        'vmContext',
                        'vm',
                        'jitContext',
                        'jit',
                        'typeReconstructor',
                    ] as $prop
                ) {
                    $this->defineProperty($id, $prop, Variable::TYPE_OBJECT);
                }
                $this->defineProperty($id, 'modules', Variable::TYPE_HASHTABLE);
                $this->defineProperty($id, 'mode', Variable::TYPE_NATIVE_LONG);
            }
        }
        if ('closure' === $lcname) {
            // Invoke metadata for indirect holders (array elements, properties; issue #72).
            $this->defineProperty($id, '__closure_target', Variable::TYPE_STRING);
        }
        if ('splobjectstorage' === $lcname) {
            $this->splObjectStorageClassId = $id;
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
        }
        if ('weakreference' === $lcname) {
            $this->defineProperty($id, '__weak_target', Variable::TYPE_NULL);
        }
        if ('weakmap' === $lcname) {
            $this->defineProperty($id, '__weak_map', Variable::TYPE_HASHTABLE);
        }
        if ('phpcompiler\\vm\\variable' === $lcname) {
            foreach ([
                'type_undefined' => \PHPCompiler\VM\Variable::TYPE_UNDEFINED,
                'type_null' => \PHPCompiler\VM\Variable::TYPE_NULL,
                'type_integer' => \PHPCompiler\VM\Variable::TYPE_INTEGER,
                'type_float' => \PHPCompiler\VM\Variable::TYPE_FLOAT,
                'type_boolean' => \PHPCompiler\VM\Variable::TYPE_BOOLEAN,
                'type_string' => \PHPCompiler\VM\Variable::TYPE_STRING,
                'type_array' => \PHPCompiler\VM\Variable::TYPE_ARRAY,
                'type_object' => \PHPCompiler\VM\Variable::TYPE_OBJECT,
                'type_indirect' => \PHPCompiler\VM\Variable::TYPE_INDIRECT,
                'type_string_offset' => \PHPCompiler\VM\Variable::TYPE_STRING_OFFSET,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpparser\\parserfactory' === $lcname || 'parserfactory' === $lcname) {
            foreach ([
                'prefer_php7' => \PhpParser\ParserFactory::PREFER_PHP7,
                'prefer_php5' => \PhpParser\ParserFactory::PREFER_PHP5,
                'only_php7' => \PhpParser\ParserFactory::ONLY_PHP7,
                'only_php5' => \PhpParser\ParserFactory::ONLY_PHP5,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpcompiler\\jit\\builtin' === $lcname || 'builtin' === $lcname) {
            foreach ([
                'load_type_export' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_EXPORT,
                'load_type_import' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_IMPORT,
                'load_type_embed' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_EMBED,
                'load_type_standalone' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpcfg\\script' === $lcname) {
            $this->defineProperty($id, 'main', Variable::TYPE_OBJECT);
        }
        if ('phpcfg\\func' === $lcname) {
            $this->defineProperty($id, 'cfg', Variable::TYPE_OBJECT);
            foreach ([
                'flag_public' => \PHPCfg\Func::FLAG_PUBLIC,
                'flag_protected' => \PHPCfg\Func::FLAG_PROTECTED,
                'flag_private' => \PHPCfg\Func::FLAG_PRIVATE,
                'flag_static' => \PHPCfg\Func::FLAG_STATIC,
                'flag_abstract' => \PHPCfg\Func::FLAG_ABSTRACT,
                'flag_final' => \PHPCfg\Func::FLAG_FINAL,
                'flag_returns_ref' => \PHPCfg\Func::FLAG_RETURNS_REF,
                'flag_closure' => \PHPCfg\Func::FLAG_CLOSURE,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpcompiler\\jit\\variable' === $lcname || 'variable' === $lcname) {
            foreach ([
                'type_null' => \PHPCompiler\JIT\Variable::TYPE_NULL,
                'type_native_long' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_LONG,
                'type_native_bool' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_BOOL,
                'type_native_double' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_DOUBLE,
                'type_string' => \PHPCompiler\JIT\Variable::TYPE_STRING,
                'type_object' => \PHPCompiler\JIT\Variable::TYPE_OBJECT,
                'type_value' => \PHPCompiler\JIT\Variable::TYPE_VALUE,
                'type_hashtable' => \PHPCompiler\JIT\Variable::TYPE_HASHTABLE,
                'is_native_array' => \PHPCompiler\JIT\Variable::IS_NATIVE_ARRAY,
                'is_refcounted' => \PHPCompiler\JIT\Variable::IS_REFCOUNTED,
                'kind_variable' => \PHPCompiler\JIT\Variable::KIND_VARIABLE,
                'kind_value' => \PHPCompiler\JIT\Variable::KIND_VALUE,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phptypes\\type' === $lcname || 'type' === $lcname) {
            foreach (['type_null'=>\PHPTypes\Type::TYPE_NULL,'type_boolean'=>\PHPTypes\Type::TYPE_BOOLEAN,'type_long'=>\PHPTypes\Type::TYPE_LONG,'type_double'=>\PHPTypes\Type::TYPE_DOUBLE,'type_string'=>\PHPTypes\Type::TYPE_STRING,'type_object'=>\PHPTypes\Type::TYPE_OBJECT,'type_array'=>\PHPTypes\Type::TYPE_ARRAY,'type_callable'=>\PHPTypes\Type::TYPE_CALLABLE,'type_union'=>\PHPTypes\Type::TYPE_UNION,'type_intersection'=>\PHPTypes\Type::TYPE_INTERSECTION] as $name=>$value) {
                $this->classConstants[$id][$name] = ['type'=>Variable::TYPE_NATIVE_LONG,'value'=>$value];
            }
        }
        if ('phpcompiler\\runtime' === $lcname || 'runtime' === $lcname) {
            foreach ([
                'mode_normal' => \PHPCompiler\Runtime::MODE_NORMAL,
                'mode_aot' => \PHPCompiler\Runtime::MODE_AOT,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
    }

    /**
     * @return array<string, array<string, true>>
     */
    private static function externalCfgArrayPropertyMap(): array
    {
        return [
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
                'scope' => true,
                'args' => true,
                'constants' => true,
            ],
            'phpcfg\\script' => [
                'functions' => true,
            ],
            'phpcfg\\func' => [
                'params' => true,
            ],
        ];
    }

    /**
     * Pre-register hashtable slots for vendor CFG array properties (issue #828).
     */
    private function seedExternalClassProperties(int $classId, string $lcname): void
    {
        $arrayProps = self::externalCfgArrayPropertyMap();
        if (!isset($arrayProps[$lcname])) {
            return;
        }
        foreach (array_keys($arrayProps[$lcname]) as $propName) {
            $this->defineProperty($classId, $propName, Variable::TYPE_HASHTABLE);
        }
    }

    /**
     * JIT storage type for properties on vendor CFG / compiler objects (e.g. PHPCfg\Block::$children).
     */
    public function externalPropertyJitType(string $class, string $name): int
    {
        $lcClass = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        $lcName = strtolower($name);
        $arrayProps = self::externalCfgArrayPropertyMap();

        if (isset($arrayProps[$lcClass][$lcName])) {
            return Variable::TYPE_HASHTABLE;
        }

        if (str_starts_with($lcClass, 'phpcfg\\script')) {
            if ('main' === $lcName) {
                return Variable::TYPE_OBJECT;
            }
            if ('functions' === $lcName) {
                return Variable::TYPE_HASHTABLE;
            }
        }
        if (str_starts_with($lcClass, 'phpcfg\\func')) {
            if ('cfg' === $lcName) {
                return Variable::TYPE_OBJECT;
            }
            if ('params' === $lcName) {
                return Variable::TYPE_HASHTABLE;
            }
        }
        if (str_starts_with($lcClass, 'phpcompiler\\runtime')) {
            if (in_array($lcName, [
                'compiler',
                'parser',
                'preprocessor',
                'postprocessor',
                'detector',
                'assignopresolver',
                'vmcontext',
                'vm',
                'jitcontext',
                'jit',
                'typereconstructor',
            ], true)) {
                return Variable::TYPE_OBJECT;
            }
            if ('modules' === $lcName) {
                return Variable::TYPE_HASHTABLE;
            }
            if ('mode' === $lcName) {
                return Variable::TYPE_NATIVE_LONG;
            }
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

    /**
     * @return list<string> lowercase method names declared on this class id
     */
    public function declaredMethodNames(int $classId): array
    {
        return array_keys($this->methodVisibility[$classId] ?? []);
    }

    /**
     * Walk parent chain and copy parent method visibility slots missing on $childId (#101).
     */
    public function inheritMethodVisibilityFromParent(int $childId, string $childLc): void
    {
        $parentLc = $this->parentClassLc($childLc);
        if (null === $parentLc || !isset($this->classes[$parentLc])) {
            return;
        }
        $parentId = $this->classes[$parentLc];
        foreach ($this->declaredMethodNames($parentId) as $methodLc) {
            if (!isset($this->methodVisibility[$childId][$methodLc])) {
                $this->methodVisibility[$childId][$methodLc] = $this->methodVisibility[$parentId][$methodLc];
            }
        }
        $grandparent = $this->parentClassLc($parentLc);
        if (null !== $grandparent) {
            $this->inheritMethodVisibilityFromParent($childId, $parentLc);
        }
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

    public function definePropertyDefault(int $classId, string $name, VMVariable $value): void
    {
        if (VMVariable::TYPE_ARRAY === $value->type) {
            return;
        }
        foreach ($this->properties[$classId] as $propset) {
            if ($propset[1] !== $name) {
                continue;
            }
            $this->propertyDefaults[$classId][$propset[3]] = [
                'propertyType' => $propset[2],
                'type' => Variable::fromVMVariable($value->type),
                'value' => $this->compileTimeValueFromVm($value),
            ];

            return;
        }
        throw new \LogicException("Property {$name} not defined for class {$classId}");
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
        if ('self' === $name) {
            if ('' === $this->context->scope->className) {
                throw new \LogicException('self:: used outside of class scope');
            }

            return $this->lookup($this->context->scope->className);
        }
        if ('static' === $name) {
            $called = $this->context->scope->calledClassName;
            if ('' !== $called) {
                return $this->lookup($called);
            }
            if ('' !== $this->context->scope->className) {
                return $this->lookup($this->context->scope->className);
            }
            throw new \LogicException('static:: used outside of class scope');
        }
        if ('parent' === $name) {
            if ('' === $this->context->scope->className) {
                throw new \LogicException('parent:: used outside of class scope');
            }
            $parentLc = $this->parentClassLc($this->context->scope->className);
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $this->lookup($parentLc);
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

    public function defineStaticProperty(int $classId, string $name, int $jitType, ?VMVariable $default = null): void
    {
        $key = strtolower($name);
        if (isset($this->staticPropertyGlobals[$classId][$key])) {
            return;
        }
        if (
            Variable::TYPE_NATIVE_LONG !== $jitType
            && Variable::TYPE_STRING !== $jitType
            && Variable::TYPE_NATIVE_BOOL !== $jitType
            && Variable::TYPE_NATIVE_DOUBLE !== $jitType
            && Variable::TYPE_VALUE !== $jitType
        ) {
            throw new \LogicException(
                'JIT static property requires a scalar declared type (int, string, float, bool) or boxed value'
            );
        }
        $globalName = 'sp_'.$classId.'_'.$key;
        if (Variable::TYPE_VALUE === $jitType) {
            $llvmType = $this->context->getTypeFromString('__value__*');
            $global = $this->context->module->addGlobal($llvmType, $globalName);
            $global->setInitializer($llvmType->constNull());
        } elseif (Variable::TYPE_STRING === $jitType) {
            $llvmType = $this->context->getTypeFromString('__string__*');
            $global = $this->context->module->addGlobal($llvmType, $globalName);
            $global->setInitializer($llvmType->constNull());
        } elseif (Variable::TYPE_NATIVE_BOOL === $jitType) {
            $llvmType = $this->context->getTypeFromString('int1');
            $global = $this->context->module->addGlobal($llvmType, $globalName);
            $global->setInitializer($this->staticPropertyScalarInitializer($jitType, $default));
        } else {
            $llvmTypeName = Variable::TYPE_NATIVE_DOUBLE === $jitType ? 'double' : 'int64';
            $llvmType = $this->context->getTypeFromString($llvmTypeName);
            $global = $this->context->module->addGlobal($llvmType, $globalName);
            $global->setInitializer($this->staticPropertyScalarInitializer($jitType, $default));
        }
        $this->staticPropertyGlobals[$classId][$key] = [
            'type' => $jitType,
            'global' => $global,
        ];
        if (Variable::TYPE_STRING === $jitType && null !== $default) {
            $this->initStaticStringPropertyDefault($global, $default);
        }
        if (Variable::TYPE_VALUE === $jitType && (null === $default || VMVariable::TYPE_NULL === $default->type)) {
            $this->initStaticValuePropertyNull($global);
        }
    }

    private function staticPropertyScalarInitializer(int $jitType, ?VMVariable $value): \PHPLLVM\Value
    {
        if (Variable::TYPE_NATIVE_DOUBLE === $jitType) {
            $llvmType = $this->context->getTypeFromString('double');
            $float = null !== $value && VMVariable::TYPE_FLOAT === $value->type ? $value->toFloat() : 0.0;

            return $llvmType->constReal($float);
        }
        $llvmType = $this->context->getTypeFromString(
            Variable::TYPE_NATIVE_BOOL === $jitType ? 'int1' : 'int64'
        );
        $int = 0;
        if (null !== $value) {
            $int = match ($value->type) {
                VMVariable::TYPE_INTEGER => $value->toInt(),
                VMVariable::TYPE_BOOLEAN => $value->toBool() ? 1 : 0,
                default => 0,
            };
        }

        return $llvmType->constInt($int, false);
    }

    private function initStaticStringPropertyDefault(\PHPLLVM\Value $global, VMVariable $value): void
    {
        if (VMVariable::TYPE_STRING !== $value->type) {
            throw new \LogicException('Static string property default must be a string');
        }
        $restore = $this->context->builder->getInsertBlock();
        $this->context->builder->positionAtEnd($this->context->initBlock);
        $str = $this->context->builder->load(
            $this->context->constantStringFromString($value->toString())
        );
        $owned = $this->context->builder->call(
            $this->context->lookupFunction('__string__separate'),
            $str
        );
        $this->context->builder->store($owned, $global);
        $this->context->builder->positionAtEnd($restore);
    }

    /** Allocate a null {@see __value__} box for untyped static properties (bootstrap JIT helpers). */
    private function initStaticValuePropertyNull(\PHPLLVM\Value $global): void
    {
        $restore = $this->context->builder->getInsertBlock();
        $this->context->builder->positionAtEnd($this->context->initBlock);
        $valueType = $this->context->getTypeFromString('__value__');
        $heapVal = $this->context->memory->malloc($valueType);
        $heapPtr = $this->context->builder->pointerCast(
            $heapVal,
            $this->context->getTypeFromString('__value__*')
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $heapPtr
        );
        $this->context->builder->store($heapPtr, $global);
        $this->context->builder->positionAtEnd($restore);
    }

    public function staticPropertyUnset(int $classId, string $name): void
    {
        $key = strtolower($name);
        if (!isset($this->staticPropertyGlobals[$classId][$key])) {
            throw new \LogicException("Undefined static property: {$name}");
        }
        $entry = $this->staticPropertyGlobals[$classId][$key];
        $global = $entry['global'];
        if (Variable::TYPE_VALUE === $entry['type']) {
            $valueType = $this->context->getTypeFromString('__value__');
            $heapVal = $this->context->memory->malloc($valueType);
            $heapPtr = $this->context->builder->pointerCast(
                $heapVal,
                $this->context->getTypeFromString('__value__*')
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                $heapPtr
            );
            $this->context->builder->store($heapPtr, $global);

            return;
        }
        if (Variable::TYPE_STRING === $entry['type']) {
            $this->context->builder->store(
                $this->context->getTypeFromString('__string__*')->constNull(),
                $global
            );

            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $entry['type']) {
            $this->context->builder->store(
                $this->context->getTypeFromString('int1')->constInt(0, false),
                $global
            );

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $entry['type']) {
            $this->context->builder->store(
                $this->context->getTypeFromString('double')->constReal(0.0, false),
                $global
            );

            return;
        }
        $this->context->builder->store(
            $this->context->getTypeFromString('int64')->constInt(0, false),
            $global
        );
    }

    public function staticPropertyFetch(int $classId, string $name): Variable
    {
        $key = strtolower($name);
        if (!isset($this->staticPropertyGlobals[$classId][$key])) {
            throw new \LogicException("Undefined static property: {$name}");
        }
        $entry = $this->staticPropertyGlobals[$classId][$key];
        $loaded = $this->context->builder->load($entry['global']);
        if (Variable::TYPE_VALUE === $entry['type']) {
            $var = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $loaded
            );
            $var->staticPropertyGlobal = $entry['global'];
            $var->staticPropertyType = $entry['type'];

            return $var;
        }
        $var = new Variable(
            $this->context,
            $entry['type'],
            Variable::KIND_VALUE,
            $loaded
        );
        $var->staticPropertyGlobal = $entry['global'];
        $var->staticPropertyType = $entry['type'];

        return $var;
    }

    public function staticPropertyStore(\PHPLLVM\Value $global, Variable $value, int $propertyType): void
    {
        if (Variable::TYPE_VALUE === $propertyType) {
            $this->staticPropertyStoreValueBox($global, $value);

            return;
        }
        if (Variable::TYPE_STRING === $propertyType) {
            $stored = $this->context->helper->loadValue($value);
            $this->context->builder->store($stored, $global);
            if (Variable::TYPE_STRING === $value->type) {
                $value->addref();
            }

            return;
        }
        if (Variable::TYPE_VALUE === $value->type) {
            $loaded = $this->context->builder->call(
                $this->context->lookupFunction(
                    Variable::TYPE_NATIVE_DOUBLE === $propertyType
                        ? '__value__readDouble'
                        : '__value__readLong'
                ),
                JitValueBox::valuePtrFromVariable($this->context, $value)
            );
            if (Variable::TYPE_NATIVE_BOOL === $propertyType) {
                $loaded = $this->context->builder->truncOrBitCast(
                    $loaded,
                    $this->context->getTypeFromString('int1')
                );
            }
            $this->context->builder->store($loaded, $global);

            return;
        }
        $this->context->builder->store($this->context->helper->loadValue($value), $global);
    }

    private function staticPropertyStoreValueBox(\PHPLLVM\Value $global, Variable $value): void
    {
        $valueType = $this->context->getTypeFromString('__value__');
        $valuePtrTy = $this->context->getTypeFromString('__value__*');

        if (Variable::TYPE_VALUE === $value->type) {
            $ptr = Variable::KIND_VARIABLE === $value->kind
                ? JitValueBox::pointer($this->context, $value->value)
                : $value->value;
            $this->context->builder->store($ptr, $global);
            $value->addref();

            return;
        }

        $heapVal = $this->context->memory->malloc($valueType);
        $heapPtr = $this->context->builder->pointerCast($heapVal, $valuePtrTy);
        $valueMap = $this->context->structFieldMap['__value__'];
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $this->context->builder->structGep($heapVal, $valueMap['type'])
        );

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
        } elseif (Variable::TYPE_OBJECT === $value->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeObject'),
                $heapPtr,
                $this->context->helper->loadValue($value)
            );
            $value->addref();
        } elseif (Variable::TYPE_NATIVE_LONG === $value->type || Variable::TYPE_NATIVE_BOOL === $value->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $heapPtr,
                $this->context->helper->loadValue($value)
            );
        } elseif (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeDouble'),
                $heapPtr,
                $this->context->helper->loadValue($value)
            );
        } else {
            throw new \LogicException(
                'JIT static property boxed store does not support value type '
                .Variable::getStringType($value->type)
            );
        }

        $this->context->builder->store($heapPtr, $global);
    }

    public function emitInstanceOf(Variable $expr, string $className): Variable
    {
        $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
        $objMap = $this->context->structFieldMap['__object__'];

        if (Variable::TYPE_OBJECT === $expr->type) {
            $obj = $this->context->helper->loadValue($expr);
            $classId = $this->context->builder->load(
                $this->context->builder->structGep($obj, $objMap['class_id'])
            );
            $match = $this->emitClassIdInstanceOf($classId, $className);

            return new Variable(
                $this->context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $match
            );
        }

        if (Variable::TYPE_VALUE === $expr->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($this->context, $expr);
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
            $matches = $this->emitClassIdInstanceOf($classId, $className);
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

    private function emitClassIdInstanceOf(PHPLLVM\Value $classId, string $className): PHPLLVM\Value
    {
        $matchingIds = $this->classIdsInstanceOf($className);
        if ([] === $matchingIds) {
            $expectedId = $this->lookup($className);

            return $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $classId,
                $this->context->constantFromInteger($expectedId, 'int64')
            );
        }
        if (1 === \count($matchingIds)) {
            return $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $classId,
                $this->context->constantFromInteger($matchingIds[0], 'int64')
            );
        }
        $match = null;
        foreach ($matchingIds as $id) {
            $cmp = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $classId,
                $this->context->constantFromInteger($id, 'int64')
            );
            $match = null === $match ? $cmp : $this->context->builder->or($match, $cmp);
        }

        return $match;
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
    private function compileTimeValueFromVm(VMVariable $value)
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

    public function propertySlotFor(PHPLLVM\Value $obj, string $class, string $name): PHPLLVM\Value
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
                return $this->propertySlotPtr($obj, $propset[3]);
            }
        }

        throw new \LogicException('Property slot not found: '.$class.'::$'.$name);
    }

    public function propertyFetch(PHPLLVM\Value $obj, string $class, string $name): Variable
    {
        $classId = $this->lookup('' !== $class ? $class : 'stdclass');
        $className = $this->classNameForId($classId);
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
                    $storage = BasicBlockHelper::entryAlloca($this->context, $valueType);
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
                    $var->objectPropertyReceiver = $obj;
                    $var->objectPropertyName = $propset[1];
                    $var->objectPropertyClassName = $className;
                    $this->slotReceivers[spl_object_id($slot)] = $obj;

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
                    $var->objectPropertyReceiver = $obj;
                    $var->objectPropertyName = $propset[1];
                    $var->objectPropertyClassName = $className;
                    $this->slotReceivers[spl_object_id($slot)] = $obj;

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
                $var->objectPropertyReceiver = $obj;
                $var->objectPropertyName = $propset[1];
                $var->objectPropertyClassName = $className;
                $this->slotReceivers[spl_object_id($slot)] = $obj;

                return $var;
            }
        }
        throw new \LogicException("Could not find property $name for class $classId");
    }

    public function storeInstanceProperty(
        PHPLLVM\Value $obj,
        string $class,
        string $name,
        Variable $value
    ): void {
        $classId = $this->lookup('' !== $class ? $class : 'stdclass');
        $nameId = $this->propNameMap[$name] ?? null;
        if (null === $nameId) {
            $this->defineProperty($classId, $name, Variable::TYPE_STRING);
            $nameId = $this->propNameMap[$name];
        }
        foreach ($this->properties[$classId] as $propset) {
            if ($propset[0] === $nameId) {
                $this->propertyStore(
                    $this->propertySlotPtr($obj, $propset[3]),
                    $value,
                    $propset[2]
                );

                return;
            }
        }
        throw new \LogicException("Could not find property {$name} for class {$classId}");
    }

    /**
     * Runtime property name from a variable (`$obj->$prop`, issue #1227).
     */
    public function propertyFetchDynamic(
        PHPLLVM\Value $obj,
        string $class,
        Variable $nameVar
    ): Variable {
        $classId = $this->lookup('' !== $class ? $class : 'stdclass');
        $props = $this->properties[$classId] ?? [];
        if ([] === $props) {
            throw new \LogicException('Dynamic property fetch requires at least one declared property on '.$class);
        }

        $nameStr = JitNativeString::coerce($this->context, $nameVar);
        if (Variable::TYPE_STRING !== $nameStr->type) {
            throw new \LogicException('Dynamic property name must coerce to string');
        }
        $runtimeName = $this->context->helper->loadValue($nameStr);

        $fn = BasicBlockHelper::parentFunction($this->context);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('dyn_prop_done');
        $exit = $fn->appendBasicBlock('dyn_prop_exit');
        $fallback = $fn->appendBasicBlock('dyn_prop_undef');
        $destSlot = JitValueBox::alloc($this->context);
        $checkBlock = $entry;
        foreach ($props as $i => $propset) {
            $this->context->builder->positionAtEnd($checkBlock);
            $propName = $propset[1];
            $litLoaded = $this->context->builder->load($this->context->constantStringFromString($propName));
            $match = JitStringCompare::identical($this->context, $runtimeName, $litLoaded);
            $caseBlock = $fn->appendBasicBlock('dyn_prop_case_'.$classId.'_'.$i);
            $nextCheck = $i + 1 < count($props)
                ? $fn->appendBasicBlock('dyn_prop_try_'.$classId.'_'.($i + 1))
                : $fallback;
            $this->context->builder->branchIf($match, $caseBlock, $nextCheck);
            $this->context->builder->positionAtEnd($caseBlock);
            $fetched = $this->propertyFetch($obj, $class, $propName);
            $this->boxFetchedPropertyIntoValue($destSlot, $fetched, $propset[2]);
            $this->context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $this->context->builder->positionAtEnd($fallback);
        $this->context->builder->call($this->context->lookupFunction('abort'));
        $this->context->builder->positionAtEnd($done);
        $this->context->builder->branch($exit);
        $this->context->builder->positionAtEnd($exit);

        return new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $destSlot
        );
    }

    private function boxFetchedPropertyIntoValue(
        PHPLLVM\Value $destSlot,
        Variable $fetched,
        int $propertyType
    ): void {
        $destPtr = JitValueBox::pointer($this->context, $destSlot);
        if (Variable::TYPE_VALUE === $propertyType) {
            $this->context->builder->call(
                $this->context->lookupFunction('__object__load_value_slot'),
                $fetched->objectPropertySlot,
                $destSlot
            );

            return;
        }
        if (Variable::TYPE_HASHTABLE === $propertyType) {
            $htPtr = $this->context->builder->pointerCast(
                $this->context->builder->load($fetched->objectPropertySlot),
                $this->context->getTypeFromString('__hashtable__*')
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $htPtr
            );

            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $propertyType) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $propertyType) {
            JitValueBox::writeBool(
                $this->context,
                $destSlot,
                $this->context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $propertyType) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeDouble'),
                $destPtr,
                $this->context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_STRING === $propertyType) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                $destPtr,
                $this->context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_OBJECT === $propertyType) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeObject'),
                $destPtr,
                $this->context->builder->load($fetched->value)
            );

            return;
        }

        throw new \LogicException(
            'Dynamic property fetch JIT box unsupported type: '.Variable::getStringType($propertyType)
        );
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

        if (Variable::TYPE_OBJECT === $propertyType && Variable::TYPE_OBJECT === $value->type) {
            $stored = $this->context->builder->pointerCast(
                $this->context->helper->loadValue($value),
                $voidPtr
            );
            $this->context->builder->store($stored, $slot);
            $value->addref();

            return;
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

        if (Variable::TYPE_VALUE === $propertyType && Variable::TYPE_OBJECT === $value->type) {
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
            if (Variable::TYPE_NATIVE_LONG === $value->type) {
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong'),
                    $heapPtr,
                    $this->context->helper->loadValue($value)
                );
                $this->context->builder->store(
                    $this->context->builder->pointerCast($heapPtr, $voidPtr),
                    $slot
                );

                return;
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble'),
                    $heapPtr,
                    $this->context->helper->loadValue($value)
                );
                $this->context->builder->store(
                    $this->context->builder->pointerCast($heapPtr, $voidPtr),
                    $slot
                );

                return;
            }
            if (Variable::TYPE_NATIVE_BOOL === $value->type) {
                JitValueBox::writeBool(
                    $this->context,
                    $heapVal,
                    $this->context->helper->loadValue($value)
                );
                $this->context->builder->store(
                    $this->context->builder->pointerCast($heapPtr, $voidPtr),
                    $slot
                );

                return;
            }
            if (Variable::TYPE_NULL === $value->type) {
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    $heapPtr
                );
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
