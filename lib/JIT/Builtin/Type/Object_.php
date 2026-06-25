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
use PHPCompiler\Block;
use PHPCompiler\ClassConstVisibility;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PseudoClassScope;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\ClassConstFetchHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\EnumCasesHelper;
use PHPCompiler\JIT\EnumFromHelper;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\Builtin\Refcount;
use PHPCompiler\JIT\Builtin\Type;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\VM\LazyGhostTraitSupport;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\MagicMethodDispatch;
use PHPCompiler\JIT\PropertyHookDispatch;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\EnumCasePropertyJitHelper;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\TraitCompositionConflictMessage;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM;

class Object_ extends Type {
    public PHPLLVM\Type $pointer;
    private array $classes = [];
    /** @var array<string, true> lowercase enum name => registered (#1373, #1356) */
    private array $enums = [];

    /** @var array<int, list<string>> */
    private array $enumCaseOrder = [];

    /** @var array<int, array<string, string>> */
    private array $enumCaseCanonicalNames = [];

    /** @var array<int, ?string> */
    private array $enumBackedType = [];

    /** @var array<int, string> class id => canonical name */
    private array $classIdToName = [];
    /** @var array<string, string> alias lc => canonical class lc (#3178) */
    private array $classAliasToOriginalLc = [];
    /** @var array<string, string> declaring class lc => parent class lc (#1858) */
    private array $classParentLc = [];
    /** @var array<string, list<string>> class lc => interface lc names (#1357, #3077) */
    private array $classInterfacesLc = [];
    /** @var array<string, list<string>> class lc => trait FQCNs from USE TRAIT (#3119) */
    private array $classUsedTraitNames = [];
    /** @var array<string, list<string>> interface lc => parent interface lc names */
    private array $interfaceExtendsLc = [];
    /** @var array<string, true> interface lc => registered */
    private array $interfaceClassLcs = [];
    /** @var array<string, true> trait lc => registered (#3789) */
    private array $traitClassLcs = [];
    /** @var array<string, true> user attribute class lc => registered (#6450) */
    private array $attributeClassLcs = [];
    /** @var array<int, array<string, string>> class id => method lc => trait lc (#3789) */
    private array $classTraitMethodSources = [];
    /** @var array<int, array<string, Block>> trait id => method lc => CFG block (#3789) */
    private array $traitMethodBlocks = [];
    /** @var array<string, list<string>> class lc => transitive interface lc (lazy) */
    private array $classAllInterfacesLc = [];
    private array $properties = [];
    private array $propNameMap = [];
    /** @var array<int, array<string, int>> class id => method lc => visibility flags */
    private array $methodVisibility = [];
    /** @var array<int, array<string, int>> class id => property lc => visibility flags (#3159) */
    private array $propertyVisibility = [];

    /** @var array<int, array<string, int>> class id => property lc => asymmetric set visibility (#3165) */
    private array $propertySetVisibility = [];
    private array $propertyGetVisibility = [];

    /** @var array<int, array<string, list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>>> */
    private array $propertyDnfArms = [];

    /** @var array<int, array<string, list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>>> */
    private array $staticPropertyDnfArms = [];

    /** @var array<int, array<int, true>> class id => property slot => true when declared type allows null (#5220) */
    private array $propertyAllowsNullSlots = [];
    /** @var array<int, array<string, string>> class id => method lc => declared casing (#3118) */
    private array $methodDisplayNames = [];
    /** @var array<int, Block> class id => __destruct CFG block (#4013) */
    private array $destructorBlocks = [];

    /** @var array<int, true> class ids with a compiled __construct body */
    private array $hasConstructor = [];
    /** @var array<int, true> vendor/external classes without lowered methods (#2666) */
    private array $externalOnlyClassIds = [];
    /** @var array<int, array<string, array{type: int, value: int|float|bool|string|null}>> */
    private array $classConstants = [];

    /** @var array<int, array<string, int>> class id => const lc => visibility flags (#4651, #6664) */
    private array $constVisibility = [];

    /** @var array<int, array<string, string>> class id => const key lc => canonical display name */
    private array $classConstDisplayNames = [];

    /** @var array<int, array<string, string>> class id => const key lc => trait FQCN when imported via use Trait */
    private array $traitConstSources = [];

    /** @var array<string, PHPLLVM\Value> singleton __object__* globals for object class constants (#3196) */
    private array $classConstObjectGlobals = [];

    /** @var array<string, PHPLLVM\Value> immortal __hashtable__* globals for array class constants (#4900) */
    private array $classConstHashtableGlobals = [];

    /** @var array<int, array<int, array{propertyType: int, type: int, value: int|float|bool|string|null}>> */
    private array $propertyDefaults = [];

    /** @var array<int, array<int, int>> class id => property slot => instantiated class id (#3391) */
    private array $runtimePropertyNewDefaults = [];
    /**
     * @var array<int, array<string, array{type: int, global: \PHPLLVM\Value}>>
     *     class id => property lc => typed LLVM global
     */
    private array $staticPropertyGlobals = [];
    /** @var array<int, array<string, int>> class id => static prop lc => visibility (#6785) */
    private array $staticPropertyVisibility = [];
    /** @var array<int, array<string, int>> class id => static prop lc => asymmetric set visibility (#6769) */
    private array $staticPropertySetVisibility = [];
    /** @var array<int, array<string, int>> class id => static prop lc => asymmetric get visibility (#8751) */
    private array $staticPropertyGetVisibility = [];
    /** @var array<int, array<string, int>> class id => static prop lc => declaring class id (#6785) */
    private array $staticPropertyDeclaringClassId = [];
    /** @var array<int, array<string, int>> class id => instance prop lc => declaring trait/class id (#7418) */
    private array $instancePropertyDeclaringClassId = [];

    private ?int $splObjectStorageClassId = null;

    private ?int $weakReferenceClassId = null;

    private ?int $weakMapClassId = null;

    private bool $traversableInterfacesSeeded = false;

    private bool $zendBuiltinInterfacesSeeded = false;

    private bool $lazyGhostTraitSeeded = false;

    /** @var array<int, true> class ids that use LazyGhostTrait (#6096) */
    private array $lazyGhostTraitClassIds = [];

    /** @var array<int, true> class ids declared readonly (issue #1360) */
    private array $readonlyClassIds = [];

    /** @var array<int, true> class ids with #[\AllowDynamicProperties] or stdClass (#3467, #4570) */
    private array $allowsDynamicPropertiesClassIds = [];

    /** @var array<int, array<string, true>> class id => property lc => true (#3149, #3432) */
    private array $readonlyPropertyNames = [];

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
            $this->context->getTypeFromString('int8'),
            $this->context->getTypeFromString('int8'),
            $this->context->getTypeFromString('int32'),
            $this->context->getTypeFromString('int8'),
            $this->context->getTypeFromString('int32'),
        );
        $this->context->structFieldMap['__object__'] = [
            'ref' => 0,
            'class_id' => 1,
            'constructed' => 2,
            'lazy_pending' => 3,
            'lazy_ghost' => 4,
            'lazy_init_index' => 5,
            'dynamic_readonly' => 6,
            'prop_count' => 7,
        ];
        $this->pointer = $this->context->getTypeFromString('__object__*');
        \PHPCompiler\JIT\ReadonlyBridge::registerDeclarations($this->context);
        \PHPCompiler\JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
        \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
        \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
        \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
        // JitThrow linked on demand when compiling try/catch (#1056).

        $this->registerFn('__object__load_value_slot', 'void', ['void**', '__value__*']);
        $this->registerFn('__object__prop_count', 'int32', ['__object__*']);
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
        $this->implementObjectPropCount();
        $this->implementValueReadObject();
        $this->implementValueWriteObject();
    }

    public function shutdown(): void
    {
        $this->implementInvokeDestructor();
    }

    /** Emit shutdown destructor pass into the current IR insertion point (#4013). */
    public function emitShutdownDestructorsCall(): void
    {
        if (!$this->hasUserDestructors()) {
            return;
        }
        \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('phpc_gc_run_shutdown_destructors')
        );
    }

    public function recordDestructorBlock(int $classId, Block $block): void
    {
        $this->destructorBlocks[$classId] = $block;
    }

    public function hasUserDestructors(): bool
    {
        return [] !== $this->classIdsWithDestructor();
    }

    /**
     * @return list<int>
     */
    private function classIdsWithDestructor(): array
    {
        $ids = [];
        foreach ($this->methodVisibility as $classId => $methods) {
            if (isset($methods['__destruct'])) {
                $ids[] = $classId;
            }
        }

        return $ids;
    }

    private function implementInvokeDestructor(): void
    {
        $objPtr = $this->context->getTypeFromString('__object__*');
        $void = $this->context->getTypeFromString('void');
        $fnType = $this->context->context->functionType($void, false, $objPtr);
        $fn = $this->context->module->getNamedFunction('__object__invoke_destructor');
        if (null !== $fn && $fn->countBasicBlocks() > 0) {
            return;
        }
        if (null === $fn) {
            $fn = $this->context->module->addFunction('__object__invoke_destructor', $fnType);
            $this->context->registerFunction('__object__invoke_destructor', $fn);
        }
        $entry = $fn->appendBasicBlock('entry');
        $this->context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $classIds = $this->classIdsWithDestructor();
        if ([] === $classIds) {
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();

            return;
        }
        $constructed = $this->context->builder->load(
            $this->context->builder->structGep($obj, $this->context->structFieldMap['__object__']['constructed'])
        );
        $notReady = $fn->appendBasicBlock('destruct_not_constructed');
        $ready = $fn->appendBasicBlock('destruct_ready');
        $done = $fn->appendBasicBlock('destruct_done');
        $isReady = $this->context->builder->icmp(
            PHPLLVM\Builder::INT_NE,
            $constructed,
            $this->context->getTypeFromString('int8')->constInt(0, false)
        );
        $this->context->builder->branchIf($isReady, $ready, $notReady);
        $this->context->builder->positionAtEnd($notReady);
        $this->context->builder->branch($done);
        $this->context->builder->positionAtEnd($ready);
        $this->emitDestructDispatchForObject($fn, $obj, $classIds, $done);
        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
    }

    /**
     * @param list<int> $classIds
     */
    private function emitDestructDispatchForObject(
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\Value $obj,
        array $classIds,
        PHPLLVM\BasicBlock $outerDone
    ): void {
        $objMap = $this->context->structFieldMap['__object__'];
        $classIdVal = $this->context->builder->load(
            $this->context->builder->structGep($obj, $objMap['class_id'])
        );
        if (1 === \count($classIds)) {
            $onlyId = $classIds[0];
            $callBlock = $fn->appendBasicBlock('destruct_magic_single_call');
            $skipBlock = $fn->appendBasicBlock('destruct_magic_single_skip');
            $expected = $this->context->constantFromInteger($onlyId, 'int64');
            $isId = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classIdVal, $expected);
            $this->context->builder->branchIf($isId, $callBlock, $skipBlock);
            $this->context->builder->positionAtEnd($callBlock);
            $this->emitDestructMagicCallForClass($obj, $onlyId);
            $this->context->builder->branch($outerDone);
            $this->context->builder->positionAtEnd($skipBlock);
            $this->context->builder->branch($outerDone);

            return;
        }
        $done = $fn->appendBasicBlock('destruct_magic_done');
        $fallback = $fn->appendBasicBlock('destruct_magic_unknown');
        $caseBlocks = [];
        foreach ($classIds as $id) {
            $caseBlocks[] = $fn->appendBasicBlock('destruct_magic_class_'.$id);
        }
        $checkBlock = $this->context->builder->getInsertBlock();
        foreach ($classIds as $i => $id) {
            $this->context->builder->positionAtEnd($checkBlock);
            $expected = $this->context->constantFromInteger($id, 'int64');
            $isId = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classIdVal, $expected);
            $nextCheck = $i + 1 < \count($classIds)
                ? $fn->appendBasicBlock('destruct_magic_try_'.($i + 1))
                : $fallback;
            $this->context->builder->branchIf($isId, $caseBlocks[$i], $nextCheck);
            $this->context->builder->positionAtEnd($caseBlocks[$i]);
            $this->emitDestructMagicCallForClass($obj, $id);
            $this->context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $this->context->builder->positionAtEnd($fallback);
        $this->context->builder->branch($done);
        $this->context->builder->positionAtEnd($done);
        $this->context->builder->branch($outerDone);
    }

    private function emitDestructMagicCallForClass(PHPLLVM\Value $obj, int $classId): void
    {
        $className = $this->classNameForId($classId);
        $proxyName = strtolower($className).'::'.'__destruct';
        if (!$this->context->functionIsRegistered($proxyName)) {
            return;
        }
        $refVirtual = $this->context->builder->pointerCast(
            $obj,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->refcount->addref($refVirtual);
        $this->context->builder->call(
            $this->context->lookupFunction('phpc_destruct_set_allow_delref'),
            $this->context->getTypeFromString('int32')->constInt(0, false)
        );
        $objVar = new Variable(
            $this->context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
        $toCall = $this->context->resolveFunctionProxy($proxyName);
        $prevStrict = $this->context->callerStrictTypes;
        $this->context->callerStrictTypes = false;
        $toCall->call($this->context, $objVar);
        $this->context->callerStrictTypes = $prevStrict;
    }

    /** Property slot count stored at allocation — replaces phpc_object_prop_count (#6749). */
    private function implementObjectPropCount(): void
    {
        $fn = $this->context->lookupFunction('__object__prop_count');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $this->context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $map = $this->context->structFieldMap['__object__'];
        $count = $this->context->builder->load(
            $this->context->builder->structGep($obj, $map['prop_count'])
        );
        $this->context->builder->returnValue($count);
        $this->context->builder->clearInsertionPosition();
    }

    private function storePropCountField(PHPLLVM\Value $obj, int $propCount): void
    {
        $map = $this->context->structFieldMap['__object__'];
        $this->context->builder->store(
            $this->context->constantFromInteger($propCount, 'int32'),
            $this->context->builder->structGep($obj, $map['prop_count'])
        );
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
        $voidPtr = $this->context->getTypeFromString('void*');
        $loaded = $this->context->builder->pointerCast(
            $this->context->builder->load($slot),
            $voidPtr
        );
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
        // Zend zval object assignment retains the value (#4096); temp RHS delref must not drop last ref.
        $refVirtual = $this->context->builder->pointerCast(
            $object,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__ref__addref'),
            $refVirtual
        );
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
        $i8 = $this->context->getTypeFromString('int8');
        $this->context->builder->store(
            $i8->constInt(0, false),
            $this->context->builder->structGep($obj, $map['lazy_pending'])
        );
        $this->context->builder->store(
            $i8->constInt(0, false),
            $this->context->builder->structGep($obj, $map['lazy_ghost'])
        );
        $this->context->builder->store(
            $this->context->getTypeFromString('int32')->constInt(-1, true),
            $this->context->builder->structGep($obj, $map['lazy_init_index'])
        );
        $this->context->builder->store(
            $i8->constInt(0, false),
            $this->context->builder->structGep($obj, $map['dynamic_readonly'])
        );
        $this->storePropCountField($obj, $propCount);

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
            $this->initRuntimePropertyNewDefaults($obj, $classId);
            $this->initEmptyHashtableProperties($obj, $classId);
            $this->initEmptyValueProperties($obj, $classId);
        }

        if ($this->isSplObjectStorageClass($classId)) {
            $ht = HashTableHelper::alloc($this->context);
            $voidPtr = $this->context->getTypeFromString('void*');
            $this->context->builder->store(
                $this->context->builder->pointerCast($ht, $voidPtr),
                $this->propertySlotPtr($obj, 0)
            );
        }

        $propCount = \count($this->properties[$classId] ?? []);
        $savedInsert = null;
        try {
            $savedInsert = $this->context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
        if (null !== $savedInsert) {
            $this->context->builder->positionAtEnd($savedInsert);
        }
        $this->context->builder->call(
            $this->context->lookupFunction('phpc_gc_register'),
            $this->context->builder->pointerCast($obj, $this->context->getTypeFromString('int8*')),
            $this->context->constantFromInteger($propCount, 'int32')
        );

        return $obj;
    }

    /**
     * `new static()` / runtime class operand — dispatch allocate by class_id (#4792).
     */
    private static function ensureStrNcasecmp(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        if (null !== $context->module->getNamedFunction('strncasecmp')) {
            return;
        }
        $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
        $fn = $context->module->addFunction('strncasecmp', $ft);
        $context->registerFunction('strncasecmp', $fn);
    }

    /** Resolve declared class id from runtime class name cstring (#4940). */
    public function classIdFromRuntimeName(PHPLLVM\Value $namePtr, PHPLLVM\Value $nameLen): PHPLLVM\Value
    {
        $fn = \PHPCompiler\JIT\BasicBlockHelper::parentFunction($this->context);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('class_name_id_done');
        $fail = $fn->appendBasicBlock('class_name_id_fail');
        $resultSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('int64')
        );
        $this->context->builder->store(
            $this->context->constantFromInteger(-1, 'int64'),
            $resultSlot
        );
        $i8p = $this->context->getTypeFromString('int8*');
        $check = $entry;
        $hasCase = false;
        foreach ($this->allClassNamesById() as $id => $className) {
            $hasCase = true;
            $case = $fn->appendBasicBlock('class_name_id_'.$id);
            $next = $fn->appendBasicBlock('class_name_id_try_'.$id);
            $this->context->builder->positionAtEnd($check);
            $expected = $this->context->builder->pointerCast(
                $this->context->constantFromString(strtolower(ltrim($className, '\\'))),
                $i8p
            );
            self::ensureStrNcasecmp($this->context);
            $cmp = $this->context->builder->call(
                $this->context->lookupFunction('strncasecmp'),
                $namePtr,
                $expected,
                $this->context->builder->zExt($nameLen, $this->context->getTypeFromString('size_t'))
            );
            $isMatch = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
            $this->context->builder->branchIf($isMatch, $case, $next);
            $this->context->builder->positionAtEnd($case);
            $this->context->builder->store(
                $this->context->constantFromInteger($id, 'int64'),
                $resultSlot
            );
            $this->context->builder->branch($done);
            $check = $next;
        }
        if (!$hasCase) {
            $this->context->builder->branch($fail);
        } else {
            $this->context->builder->positionAtEnd($check);
            $this->context->builder->branch($fail);
        }
        $this->context->builder->positionAtEnd($fail);
        $this->context->builder->call($this->context->lookupFunction('abort'));
        $this->context->builder->positionAtEnd($done);

        return $this->context->builder->load($resultSlot);
    }

    public function allocateForRuntimeClassId(PHPLLVM\Value $classIdVal): PHPLLVM\Value
    {
        $fn = \PHPCompiler\JIT\BasicBlockHelper::parentFunction($this->context);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('new_runtime_class_done');
        $fail = $fn->appendBasicBlock('new_runtime_class_fail');
        $resultSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__object__*')
        );
        $nullObj = $this->context->getTypeFromString('__object__*')->constNull();
        $this->context->builder->store($nullObj, $resultSlot);
        $checkBlock = $entry;
        $hasCase = false;
        foreach (array_keys($this->allClassNamesById()) as $id) {
            $hasCase = true;
            $caseBlock = $fn->appendBasicBlock('new_runtime_class_case_'.$id);
            $nextCheck = $fn->appendBasicBlock('new_runtime_class_try_'.$id);
            $this->context->builder->positionAtEnd($checkBlock);
            $expected = $this->context->constantFromInteger($id, 'int64');
            $isId = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classIdVal, $expected);
            $this->context->builder->branchIf($isId, $caseBlock, $nextCheck);
            $this->context->builder->positionAtEnd($caseBlock);
            $obj = $this->allocate($id);
            $this->context->builder->store($obj, $resultSlot);
            $this->context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        if (!$hasCase) {
            $this->context->builder->branch($fail);
        } else {
            $this->context->builder->positionAtEnd($checkBlock);
            $this->context->builder->branch($fail);
        }
        $this->context->builder->positionAtEnd($fail);
        \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
        \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, 'Class not found');
        $this->context->builder->call($this->context->lookupFunction('abort'));
        $this->context->builder->positionAtEnd($done);

        return $this->context->builder->load($resultSlot);
    }

    /**
     * `: static` return — return object's class_id must be called class or a subclass (#4792).
     */
    public function emitClassIdMatchesLateStaticReturn(
        PHPLLVM\Value $actualClassId,
        PHPLLVM\Value $expectedClassId
    ): PHPLLVM\Value {
        $i1 = $this->context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($this->allClassNamesById() as $actualId => $_) {
            foreach ($this->allClassNamesById() as $expectedId => $_) {
                if (!$this->compileTimeClassIsSubclassOrEqual($actualId, $expectedId)) {
                    continue;
                }
                $isExpected = $this->context->builder->icmp(
                    PHPLLVM\Builder::INT_EQ,
                    $expectedClassId,
                    $this->context->constantFromInteger($expectedId, 'int64')
                );
                $isActual = $this->context->builder->icmp(
                    PHPLLVM\Builder::INT_EQ,
                    $actualClassId,
                    $this->context->constantFromInteger($actualId, 'int64')
                );
                $acc = $this->context->builder->or($acc, $this->context->builder->and($isExpected, $isActual));
            }
        }

        return $acc;
    }

    public function compileTimeClassIsSubclassOrEqual(int $childId, int $ancestorId): bool
    {
        if ($childId === $ancestorId) {
            return true;
        }
        $childLc = strtolower(ltrim($this->classNameForId($childId), '\\'));
        $ancestorLc = strtolower(ltrim($this->classNameForId($ancestorId), '\\'));
        $current = $childLc;
        while (true) {
            $parent = $this->parentClassLc($current);
            if (null === $parent) {
                return false;
            }
            if ($parent === $ancestorLc) {
                return true;
            }
            $current = $parent;
        }
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
        $this->storePropCountField($obj, $propCount);

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
            $this->initRuntimePropertyNewDefaults($obj, $classId);
            $this->initEmptyHashtableProperties($obj, $classId);
            $this->initEmptyValueProperties($obj, $classId);
        }

        return $obj;
    }

    /**
     * Immortal object for class constants (`public const X = new …`, #3196, #4021).
     *
     * Module-global singleton: no GC registration, non-refcounted header (php-src persistent zval).
     */
    public function allocateClassConstantObject(int $classId): PHPLLVM\Value
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
        $this->storePropCountField($obj, $propCount);

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
        // Module-global singleton: one permanent reference (php-src persistent zval).
        $this->context->builder->call(
            $this->context->lookupFunction('__ref__addref'),
            $ref
        );

        if ($propCount > 0) {
            $this->initPropertySlots($obj, $propCount);
            $this->initPropertyDefaults($obj, $classId);
            $this->initRuntimePropertyNewDefaults($obj, $classId);
            $this->initEmptyHashtableProperties($obj, $classId);
            $this->initEmptyValueProperties($obj, $classId);
        }

        return $obj;
    }

    /**
     * Immortal enum case singleton for class constants (`public const X = E::A`, #4445).
     *
     * Reuses the canonical enum case object shape without GC registration (php-src persistent zval).
     */
    public function allocateClassConstantEnumCase(
        int $enumClassId,
        string $caseName,
        Variable $backingJit
    ): PHPLLVM\Value {
        $objType = $this->context->getTypeFromString('__object__');
        $obj = $this->context->memory->mallocWithExtra(
            $objType,
            $this->context->constantFromInteger(16, 'size_t')
        );
        $map = $this->context->structFieldMap['__object__'];
        $this->context->builder->store(
            $this->context->constantFromInteger($enumClassId, 'int64'),
            $this->context->builder->structGep($obj, $map['class_id'])
        );
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(1, false),
            $this->context->builder->structGep($obj, $map['constructed'])
        );
        $enumPropCount = $this->enumHasBacking($enumClassId) ? 2 : 0;
        $this->storePropCountField($obj, $enumPropCount);
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
        $this->context->builder->call(
            $this->context->lookupFunction('__ref__addref'),
            $ref
        );
        $voidPtr = $this->context->getTypeFromString('void*');
        $nameStr = $this->context->builder->load(
            $this->context->constantStringFromString($caseName)
        );
        $this->context->builder->store(
            $this->context->builder->pointerCast($nameStr, $voidPtr),
            $this->propertySlotPtr($obj, EnumCasePropertyJitHelper::SLOT_NAME)
        );
        if ($this->enumHasBacking($enumClassId)) {
            $this->propertyStore(
                $this->propertySlotPtr($obj, EnumCasePropertyJitHelper::SLOT_VALUE),
                $backingJit,
                Variable::TYPE_VALUE
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

    private function initPropertyDefaults(PHPLLVM\Value $obj, int $classId): void
    {
        if (!isset($this->propertyDefaults[$classId])) {
            return;
        }
        foreach ($this->propertyDefaults[$classId] as $slotIndex => $entry) {
            $slot = $this->propertySlotPtr($obj, $slotIndex);
            if (!empty($entry['emptyArray'])) {
                $ht = HashTableHelper::alloc($this->context);
                $emptyHt = new Variable(
                    $this->context,
                    Variable::TYPE_HASHTABLE,
                    Variable::KIND_VALUE,
                    $ht
                );
                $this->propertyStore($slot, $emptyHt, $entry['propertyType']);

                continue;
            }
            $constEntry = isset($entry['global'])
                ? ['type' => $entry['type'], 'global' => $entry['global']]
                : ['type' => $entry['type'], 'value' => $entry['value']];
            $var = $this->jitConstantFromEntry($constEntry);
            $this->propertyStore($slot, $var, $entry['propertyType']);
        }
    }

    /**
     * Per-instance property defaults from `new` expressions (#3391, Zend zend_objects.c).
     */
    private function initRuntimePropertyNewDefaults(PHPLLVM\Value $obj, int $classId): void
    {
        if (!isset($this->runtimePropertyNewDefaults[$classId])) {
            return;
        }
        $restore = $this->context->builder->getInsertBlock();
        $propertyTypes = [];
        foreach ($this->properties[$classId] ?? [] as $propset) {
            $propertyTypes[$propset[3]] = $propset[2];
        }
        foreach ($this->runtimePropertyNewDefaults[$classId] as $slotIndex => $newClassId) {
            $slot = $this->propertySlotPtr($obj, $slotIndex);
            $child = $this->allocate($newClassId);
            if (!$this->hasConstructor($newClassId)) {
                $this->markObjectConstructed($child);
            }
            $jitVar = new Variable(
                $this->context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $child
            );
            $this->propertyStore($slot, $jitVar, $propertyTypes[$slotIndex] ?? Variable::TYPE_OBJECT);
            $this->context->builder->positionAtEnd($restore);
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
            $voidPtr = $this->context->getTypeFromString('void*');
            $loaded = $this->context->builder->pointerCast(
                $this->context->builder->load($slot),
                $voidPtr
            );
            $nullPtr = $voidPtr->constNull();
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

    /** Uninitialized {@see __value__} property slots start as boxed null (#4111, Zend typed properties). */
    private function initEmptyValueProperties(PHPLLVM\Value $obj, int $classId): void
    {
        if (!isset($this->properties[$classId])) {
            return;
        }
        $initialized = $this->propertyDefaults[$classId] ?? [];
        $valueType = $this->context->getTypeFromString('__value__');
        $voidPtr = $this->context->getTypeFromString('void*');
        $valueMap = $this->context->structFieldMap['__value__'];
        foreach ($this->properties[$classId] as $propset) {
            if (Variable::TYPE_VALUE !== $propset[2]) {
                continue;
            }
            if (isset($initialized[$propset[3]])) {
                continue;
            }
            $slot = $this->propertySlotPtr($obj, $propset[3]);
            $loaded = $this->context->builder->pointerCast(
                $this->context->builder->load($slot),
                $voidPtr
            );
            $nullPtr = $voidPtr->constNull();
            $isEmpty = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $loaded,
                $nullPtr
            );
            $fn = $this->context->builder->getInsertBlock()->getParent();
            assert($fn instanceof PHPLLVM\Value\Function_);
            $initBlock = $fn->appendBasicBlock('prop_value_init_'.$classId.'_'.$propset[3]);
            $doneBlock = $fn->appendBasicBlock('prop_value_done_'.$classId.'_'.$propset[3]);
            $this->context->builder->branchIf($isEmpty, $initBlock, $doneBlock);
            $this->context->builder->positionAtEnd($initBlock);
            $heapVal = $this->context->memory->malloc($valueType);
            $heapPtr = $this->context->builder->pointerCast(
                $heapVal,
                $this->context->getTypeFromString('__value__*')
            );
            $this->context->builder->store(
                $this->context->getTypeFromString('int8')->constInt(
                    \PHPCompiler\VM\Variable::TYPE_UNDEFINED,
                    false
                ),
                $this->context->builder->structGep($heapVal, $valueMap['type'])
            );
            $this->context->builder->store(
                $this->context->builder->pointerCast($heapPtr, $voidPtr),
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

    public function isReadonlyClass(int $classId): bool
    {
        return isset($this->readonlyClassIds[$classId]);
    }

    public function setClassAllowsDynamicProperties(int $classId, bool $allows): void
    {
        if ($allows) {
            $this->allowsDynamicPropertiesClassIds[$classId] = true;
        } else {
            unset($this->allowsDynamicPropertiesClassIds[$classId]);
        }
    }

    public function allowsDynamicProperties(int $classId): bool
    {
        return isset($this->allowsDynamicPropertiesClassIds[$classId]);
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
        if (isset($this->readonlyPropertyNames[$parentId])) {
            foreach ($this->readonlyPropertyNames[$parentId] as $propLc => $_) {
                $this->readonlyPropertyNames[$childId][$propLc] = true;
            }
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

    public function markPropertyReadonly(int $classId, string $name): void
    {
        $this->readonlyPropertyNames[$classId][strtolower($name)] = true;
    }

    public function isPropertyReadonly(int $classId, string $name): bool
    {
        return isset($this->readonlyPropertyNames[$classId][strtolower($name)]);
    }

    /**
     * @return list<int> class ids declaring $name as a readonly instance property
     */
    public function readonlyPropertyClassIdsForProperty(string $name): array
    {
        $lc = strtolower($name);
        $ids = [];
        foreach ($this->readonlyPropertyNames as $classId => $props) {
            if (isset($props[$lc])) {
                $ids[] = $classId;
            }
        }

        return $ids;
    }

    public function hasReadonlyPropertyGuards(): bool
    {
        return [] !== $this->readonlyClassIds || [] !== $this->readonlyPropertyNames;
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

    /** Parent class display name for JIT get_parent_class() when known at compile time (#3483). */
    public function parentClassDisplayName(string $className): ?string
    {
        $parentLc = $this->parentClassLc($className);
        if (null === $parentLc || !isset($this->classes[$parentLc])) {
            return null;
        }

        return $this->classNameForId($this->classes[$parentLc]);
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
        $this->ensureTraversableBuiltinInterfaces();
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
            if (in_array($wantLc, $this->allInterfacesForClassLc($current), true)) {
                return true;
            }
            if ('stringable' === $wantLc && $this->classHasImplicitStringableLc($current)) {
                return true;
            }
            $parent = $this->classParentLc[$current] ?? null;
            if (null === $parent) {
                return false;
            }
            $current = $parent;
        }
    }

    public function classHasImplicitStringableLc(string $classLc): bool
    {
        if ($this->isInterfaceClassLc($classLc) || $this->isTraitClass($classLc)) {
            return false;
        }
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->classes[$current])) {
                break;
            }
            $classId = $this->classes[$current];
            if ($this->hasMethod($classId, '__tostring')) {
                return MethodVisibility::isPublic($this->methodVisibility($classId, '__tostring'));
            }
            $parent = $this->classParentLc[$current] ?? null;
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * @param list<string> $interfaceLcs lowercase interface names
     */
    public function setClassInterfaces(string $className, array $interfaceLcs): void
    {
        $this->ensureTraversableBuiltinInterfaces();
        $lc = strtolower(ltrim($className, '\\'));
        $expanded = [];
        foreach ($interfaceLcs as $iface) {
            $expanded = array_merge($expanded, $this->expandInterfaceLc($iface));
        }
        $this->classInterfacesLc[$lc] = array_values(array_unique($expanded));
        unset($this->classAllInterfacesLc[$lc]);
    }

    public function recordClassUsedTrait(string $classLc, string $traitName): void
    {
        $lc = strtolower(ltrim($classLc, '\\'));
        $this->classUsedTraitNames[$lc] ??= [];
        if (!in_array($traitName, $this->classUsedTraitNames[$lc], true)) {
            $this->classUsedTraitNames[$lc][] = $traitName;
        }
    }

    /**
     * @return list<string>
     */
    public function usedTraitNamesForClassLc(string $classLc): array
    {
        return $this->classUsedTraitNames[strtolower(ltrim($classLc, '\\'))] ?? [];
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
        $this->ensureZendBuiltinInterfaces();

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
     * class_implements() — lowercase interface names in the result map (#7400).
     *
     * Interface operands list parent interfaces only; classes list all implemented interfaces.
     *
     * @return list<string>
     */
    public function interfacesForClassImplementsLc(string $classLc): array
    {
        $classLc = strtolower(ltrim($classLc, '\\'));
        if ($this->isInterfaceClassLc($classLc)) {
            $ifaces = [];
            foreach ($this->interfaceExtendsLc[$classLc] ?? [] as $parent) {
                $ifaces = array_merge($ifaces, $this->expandInterfaceLc($parent));
            }

            return array_values(array_unique($ifaces));
        }

        return $this->allInterfacesForClassLc($classLc);
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

    /** Zend zend_interfaces.c — UnitEnum, BackedEnum, Serializable (#6354). */
    private function ensureZendBuiltinInterfaces(): void
    {
        if ($this->zendBuiltinInterfacesSeeded) {
            return;
        }
        $this->zendBuiltinInterfacesSeeded = true;
        $this->ensureTraversableBuiltinInterfaces();
        $this->markInterfaceClass('UnitEnum');
        $this->markInterfaceClass('BackedEnum');
        $this->setInterfaceExtends('BackedEnum', ['UnitEnum']);
        $this->markInterfaceClass('Serializable');
        $this->markInterfaceClass('SeekableIterator');
        $this->setInterfaceExtends('SeekableIterator', ['Iterator']);
        $this->markInterfaceClass('Reflector');
        $this->markInterfaceClass('DOMParentNode');
        $this->markInterfaceClass('DOMChildNode');
        $this->setInterfaceExtends('DOMChildNode', ['DOMParentNode']);
        $this->markInterfaceClass('SessionHandlerInterface');
        $this->markInterfaceClass('SessionIdInterface');
        $this->setInterfaceExtends('SessionIdInterface', ['SessionHandlerInterface']);
        $this->markInterfaceClass('SessionUpdateTimestampHandlerInterface');
        $this->setInterfaceExtends('SessionUpdateTimestampHandlerInterface', ['SessionHandlerInterface']);
        $this->markInterfaceClass('Random\\Engine');
        $this->markInterfaceClass('Random\\CryptoSafeEngine');
        $this->setInterfaceExtends('Random\\CryptoSafeEngine', ['Random\\Engine']);
    }

    /** Zend traversable/iterator/iteratoraggregate hierarchy for instanceof (#4754, #4771). */
    private function ensureTraversableBuiltinInterfaces(): void
    {
        if ($this->traversableInterfacesSeeded) {
            return;
        }
        $this->traversableInterfacesSeeded = true;
        $this->lookup('Traversable');
        $this->markInterfaceClass('Traversable');
        $this->lookup('Iterator');
        $this->markInterfaceClass('Iterator');
        $this->setInterfaceExtends('Iterator', ['Traversable']);
        $this->lookup('IteratorAggregate');
        $this->markInterfaceClass('IteratorAggregate');
        $this->setInterfaceExtends('IteratorAggregate', ['Traversable']);
    }

    /** PHP 8.4 built-in LazyGhostTrait marker for trait_exists / use Trait (#6096). */
    private function ensureLazyGhostBuiltinTrait(): void
    {
        if ($this->lazyGhostTraitSeeded) {
            return;
        }
        $this->lazyGhostTraitSeeded = true;
        $this->lookup('LazyGhostTrait');
        $this->markTraitClass('lazyghosttrait');
    }

    public function markLazyGhostTraitClass(int $classId): void
    {
        $this->lazyGhostTraitClassIds[$classId] = true;
    }

    public function classUsesLazyGhostTrait(int $classId): bool
    {
        return isset($this->lazyGhostTraitClassIds[$classId]);
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

    public function setEnumBackedType(int $classId, ?string $backedType): void
    {
        $this->enumBackedType[$classId] = $backedType;
    }

    public function jitContext(): Context
    {
        return $this->context;
    }

    /** @return array<int, string> */
    public function classIdToNameEntries(): array
    {
        return $this->classIdToName;
    }

    public function isRegisteredEnumLc(string $lc): bool
    {
        return isset($this->enums[$lc]);
    }

    public function enumCaseBuiltinPropertySlotPtr(PHPLLVM\Value $obj, int $slotIndex): PHPLLVM\Value
    {
        return $this->propertySlotPtr($obj, $slotIndex);
    }

    /** @return array<int, string> */
    public function knownEnumClassIdsToNames(): array
    {
        $enumEntries = [];
        foreach ($this->classIdToName as $id => $name) {
            $lc = strtolower(ltrim($name, '\\'));
            if (isset($this->enums[$lc])) {
                $enumEntries[(int) $id] = $name;
            }
        }

        return $enumEntries;
    }

    public function isEnumClassId(int $classId): bool
    {
        $lc = $this->classLcForId($classId);

        return null !== $lc && isset($this->enums[$lc]);
    }

    public function enumHasBacking(int $classId): bool
    {
        return null !== ($this->enumBackedType[$classId] ?? null);
    }

    public function enumBackedTypeFor(int $classId): ?string
    {
        return $this->enumBackedType[$classId] ?? null;
    }

    public function isEnumClassLc(string $classLc): bool
    {
        return isset($this->enums[strtolower(ltrim($classLc, '\\'))]);
    }

    /** @return list<string> */
    public function enumCaseOrderForClass(int $classId): array
    {
        return $this->enumCaseOrder[$classId] ?? [];
    }

    /** @return int|float|bool|string|null */
    public function enumCaseBackingScalarForCase(int $classId, string $caseKey): int|float|bool|string|null
    {
        $key = strtolower($caseKey);
        if (!isset($this->classConstants[$classId][$key])) {
            throw new \LogicException('Unknown enum case backing: '.$caseKey);
        }

        return $this->classConstants[$classId][$key]['value'];
    }

    public function enumCaseCanonicalName(int $classId, string $caseKey): string
    {
        return $this->enumCaseCanonicalNames[$classId][$caseKey] ?? $caseKey;
    }

    /**
     * Zend duplicate backing detection for JIT enum case fetch (#5773, #9255).
     */
    public function duplicateBackedEnumErrorMessage(int $classId): ?string
    {
        if (!$this->isEnumClassId($classId) || null === ($this->enumBackedType[$classId] ?? null)) {
            return null;
        }
        $cases = [];
        foreach ($this->enumCaseOrderForClass($classId) as $caseKey) {
            $backing = $this->enumCaseBackingScalarForCase($classId, $caseKey);
            if (!is_int($backing) && !is_string($backing)) {
                return null;
            }
            $cases[] = [
                'name' => $this->enumCaseCanonicalName($classId, $caseKey),
                'backing' => $backing,
            ];
        }

        return \PHPCompiler\Compiler\EnumBackedCaseCheck::duplicateBackingErrorMessage(
            $this->classNameForId($classId),
            $cases
        );
    }

    public function finishEnumClass(int $classId): void
    {
        if ($this->isEnumClassId($classId)) {
            EnumCasesHelper::registerCasesMethod($this->context, $this, $classId);
            $this->defineMethodVisibility(
                $classId,
                'cases',
                \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC,
                'cases'
            );
            EnumFromHelper::registerFromMethods($this->context, $this, $classId);
        }
    }

    public function defineEnumCaseConst(int $classId, string $caseName, VMVariable $backing): void
    {
        if (!$this->isEnumClassId($classId)) {
            throw new \LogicException('defineEnumCaseConst requires an enum class id');
        }
        $key = strtolower($caseName);
        $this->enumCaseOrder[$classId][] = $key;
        $this->enumCaseCanonicalNames[$classId][$key] = $caseName;
        $this->classConstants[$classId][$key] = [
            'type' => Variable::fromVMVariable($backing->type),
            'value' => $this->compileTimeValueFromVm($backing),
        ];
    }

    public function jitEnumCaseFromBacking(int $classId, string $caseKey): Variable
    {
        if (!isset($this->classConstants[$classId][$caseKey])) {
            throw new \LogicException("Unknown enum case: {$caseKey}");
        }

        $globalName = $this->ensureEnumCaseSingletonGlobal($classId, $caseKey);

        $var = $this->jitClassConstObjectFromGlobal([
            'type' => Variable::TYPE_OBJECT,
            'global' => $globalName,
        ]);
        $var->compileTimeEnumCase = ['classId' => $classId, 'caseKey' => $caseKey];

        return $var;
    }

    public function allocEnumCaseSingletonIr(int $classId, string $caseName, Variable $backingJit): Variable
    {
        $objType = $this->context->getTypeFromString('__object__');
        $obj = $this->context->memory->mallocWithExtra(
            $objType,
            $this->context->constantFromInteger(16, 'size_t')
        );
        $map = $this->context->structFieldMap['__object__'];
        $this->context->builder->store(
            $this->context->constantFromInteger($classId, 'int64'),
            $this->context->builder->structGep($obj, $map['class_id'])
        );
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(1, false),
            $this->context->builder->structGep($obj, $map['constructed'])
        );
        $enumPropCount = 2;
        $this->storePropCountField($obj, $enumPropCount);
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
        $this->context->builder->call(
            $this->context->lookupFunction('__ref__addref'),
            $ref
        );
        $voidPtr = $this->context->getTypeFromString('void*');
        $nameStr = $this->context->builder->load(
            $this->context->constantStringFromString($caseName)
        );
        $this->context->builder->store(
            $this->context->builder->pointerCast($nameStr, $voidPtr),
            $this->propertySlotPtr($obj, EnumCasePropertyJitHelper::SLOT_NAME)
        );
        if ($this->enumHasBacking($classId)) {
            $this->propertyStore(
                $this->propertySlotPtr($obj, EnumCasePropertyJitHelper::SLOT_VALUE),
                $backingJit,
                Variable::TYPE_VALUE
            );
        }
        \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('phpc_gc_register'),
            $this->context->builder->pointerCast($obj, $this->context->getTypeFromString('int8*')),
            $this->context->constantFromInteger($enumPropCount, 'int32')
        );

        return new Variable(
            $this->context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
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
     * Register an alternate name for a JIT-declared user class (class_alias, #3178).
     *
     * php-src: zend_register_class_alias_ex — user classes/interfaces/traits/enums; no alias chains.
     */
    public function registerClassAlias(string $original, string $alias): bool
    {
        $aliasLc = strtolower(ltrim($alias, '\\'));
        $originalLc = strtolower(ltrim($original, '\\'));

        if (isset($this->classes[$aliasLc]) || isset($this->classAliasToOriginalLc[$aliasLc])) {
            return false;
        }
        if (!isset($this->classes[$originalLc])) {
            return false;
        }
        if (isset($this->classAliasToOriginalLc[$originalLc])) {
            return false;
        }

        $classId = $this->classes[$originalLc];
        if (isset($this->externalOnlyClassIds[$classId])) {
            return false;
        }

        $this->classes[$aliasLc] = $classId;
        $this->classAliasToOriginalLc[$aliasLc] = $originalLc;
        if (isset($this->interfaceClassLcs[$originalLc])) {
            $this->interfaceClassLcs[$aliasLc] = true;
            if (isset($this->interfaceExtendsLc[$originalLc])) {
                $this->interfaceExtendsLc[$aliasLc] = $this->interfaceExtendsLc[$originalLc];
            }
            if (isset($this->classInterfacesLc[$originalLc])) {
                $this->classInterfacesLc[$aliasLc] = $this->classInterfacesLc[$originalLc];
            }
            unset($this->classAllInterfacesLc[$aliasLc]);
        }
        if (isset($this->traitClassLcs[$originalLc])) {
            $this->traitClassLcs[$aliasLc] = true;
        }
        if (isset($this->classUsedTraitNames[$originalLc])) {
            $this->classUsedTraitNames[$aliasLc] = $this->classUsedTraitNames[$originalLc];
        }
        if (isset($this->enums[$originalLc])) {
            $this->enums[$aliasLc] = true;
        }

        return true;
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

    /**
     * @return list<string>
     */
    public function allDeclaredInterfaceNames(): array
    {
        $names = [];
        foreach (array_keys($this->interfaceClassLcs) as $ifaceLc) {
            $resolved = null;
            foreach ($this->classIdToName as $name) {
                if (strtolower(ltrim($name, '\\')) === $ifaceLc) {
                    $resolved = $name;
                    break;
                }
            }
            $names[] = $resolved ?? $ifaceLc;
        }

        return $names;
    }

    /**
     * User and builtin classes from DECLARE_CLASS (issue #3128).
     *
     * @return list<string>
     */
    public function allDeclaredClassNames(): array
    {
        $names = [];
        foreach (array_keys($this->classes) as $classLc) {
            if (isset($this->interfaceClassLcs[$classLc])
                || isset($this->traitClassLcs[$classLc])) {
                continue;
            }
            $resolved = null;
            foreach ($this->classIdToName as $name) {
                if (strtolower(ltrim($name, '\\')) === $classLc) {
                    $resolved = $name;
                    break;
                }
            }
            $names[] = $resolved ?? $classLc;
        }

        return $names;
    }

    /**
     * User #[Attribute] classes from DECLARE_* (#6450).
     *
     * @return list<string>
     */
    public function allDeclaredAttributeClassNames(): array
    {
        $names = [];
        foreach (array_keys($this->attributeClassLcs) as $classLc) {
            $resolved = null;
            foreach ($this->classIdToName as $name) {
                if (strtolower(ltrim($name, '\\')) === $classLc) {
                    $resolved = $name;
                    break;
                }
            }
            $names[] = $resolved ?? $classLc;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function allDeclaredTraitNames(): array
    {
        $names = [];
        foreach (array_keys($this->traitClassLcs) as $traitLc) {
            if (LazyGhostTraitSupport::isLazyGhostTrait($traitLc)) {
                continue;
            }
            $resolved = null;
            foreach ($this->classIdToName as $name) {
                if (strtolower(ltrim($name, '\\')) === $traitLc) {
                    $resolved = $name;
                    break;
                }
            }
            $names[] = $resolved ?? $traitLc;
        }

        return $names;
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
     * Lowercase registry keys for JIT unitenum_exists() — pure enums only (#6884).
     *
     * @return list<string>
     */
    public function allDeclaredUnitEnumLowerNames(): array
    {
        $names = [];
        foreach (array_keys($this->enums) as $enumLc) {
            $classId = $this->classes[$enumLc] ?? null;
            if (null === $classId || $this->enumHasBacking($classId)) {
                continue;
            }
            $names[] = $enumLc;
        }

        return $names;
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

    /**
     * After shallow clone, invoke user __clone() when the class defines it (Zend #3170).
     */
    public function invokeCloneMagicIfPresent(Block $block, PHPLLVM\Value $cloned): void
    {
        $cloneClassIds = [];
        foreach ($this->methodVisibility as $classId => $methods) {
            if (isset($methods['__clone'])) {
                $cloneClassIds[] = $classId;
            }
        }
        if ([] === $cloneClassIds) {
            return;
        }
        if (1 === count($cloneClassIds)) {
            $onlyId = $cloneClassIds[0];
            $objMap = $this->context->structFieldMap['__object__'];
            $classId = $this->context->builder->load(
                $this->context->builder->structGep($cloned, $objMap['class_id'])
            );
            $fn = $this->context->builder->getInsertBlock()->getParent();
            assert($fn instanceof PHPLLVM\Value\Function_);
            $entry = $this->context->builder->getInsertBlock();
            $callBlock = $fn->appendBasicBlock('clone_magic_single_call');
            $done = $fn->appendBasicBlock('clone_magic_single_done');
            $expected = $this->context->constantFromInteger($onlyId, 'int64');
            $isId = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classId, $expected);
            $this->context->builder->branchIf($isId, $callBlock, $done);
            $this->context->builder->positionAtEnd($callBlock);
            $this->emitCloneMagicCallForClass($block, $cloned, $onlyId);
            $this->context->builder->branch($done);
            $this->context->builder->positionAtEnd($done);

            return;
        }
        $objMap = $this->context->structFieldMap['__object__'];
        $classId = $this->context->builder->load(
            $this->context->builder->structGep($cloned, $objMap['class_id'])
        );
        $fn = $this->context->builder->getInsertBlock()->getParent();
        assert($fn instanceof PHPLLVM\Value\Function_);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('clone_magic_done');
        $fallback = $fn->appendBasicBlock('clone_magic_unknown');
        $caseBlocks = [];
        foreach ($cloneClassIds as $id) {
            $caseBlocks[] = $fn->appendBasicBlock('clone_magic_class_'.$id);
        }
        $checkBlock = $entry;
        foreach ($cloneClassIds as $i => $id) {
            $this->context->builder->positionAtEnd($checkBlock);
            $expected = $this->context->constantFromInteger($id, 'int64');
            $isId = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classId, $expected);
            $nextCheck = $i + 1 < count($cloneClassIds)
                ? $fn->appendBasicBlock('clone_magic_try_'.($i + 1))
                : $fallback;
            $this->context->builder->branchIf($isId, $caseBlocks[$i], $nextCheck);
            $this->context->builder->positionAtEnd($caseBlocks[$i]);
            $this->emitCloneMagicCallForClass($block, $cloned, $id);
            $this->context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $this->context->builder->positionAtEnd($fallback);
        $this->context->builder->branch($done);
        $this->context->builder->positionAtEnd($done);
    }

    private function emitCloneMagicCallForClass(Block $block, PHPLLVM\Value $cloned, int $classId): void
    {
        $className = $this->classNameForId($classId);
        $proxyName = strtolower($className).'::'.'__clone';
        if (!$this->context->functionIsRegistered($proxyName)) {
            return;
        }
        $objVar = new Variable(
            $this->context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $cloned
        );
        $toCall = $this->context->resolveFunctionProxy($proxyName);
        $prevStrict = $this->context->callerStrictTypes;
        $this->context->callerStrictTypes = $block->strictTypes;
        $toCall->call($this->context, $objVar);
        $this->context->callerStrictTypes = $prevStrict;
    }

    /** Reset instance slots to null (lazy ghost before initializer, #4940). */
    public function resetInstancePropertySlots(PHPLLVM\Value $obj, PHPLLVM\Value $classIdVal): void
    {
        $this->dispatchByRuntimeClassId($classIdVal, function (int $id) use ($obj): void {
            $propCount = \count($this->properties[$id] ?? []);
            if ($propCount > 0) {
                $this->initPropertySlots($obj, $propCount);
            }
        }, 'lazy_reset_props');
    }

    /** Apply declared defaults for lazy ghost init (#4940). */
    public function applyLazyGhostPropertyDefaults(PHPLLVM\Value $obj, PHPLLVM\Value $classIdVal): void
    {
        $this->dispatchByRuntimeClassId($classIdVal, function (int $id) use ($obj): void {
            $this->initPropertyDefaults($obj, $id);
        }, 'lazy_defaults');
    }

    public function readRuntimeClassId(PHPLLVM\Value $obj): PHPLLVM\Value
    {
        $objMap = $this->context->structFieldMap['__object__'];

        return $this->context->builder->load(
            $this->context->builder->structGep($obj, $objMap['class_id'])
        );
    }

    /** Reinitialize one clone-with listed property to its compile-time default (#10310). */
    public function reinitCloneWithPropertyDefault(PHPLLVM\Value $obj, PHPLLVM\Value $classIdVal, string $propName): void
    {
        $this->dispatchByRuntimeClassId($classIdVal, function (int $id) use ($obj, $propName): void {
            $slotIndex = null;
            $propertyType = null;
            foreach ($this->properties[$id] ?? [] as $propset) {
                if ($propset[1] === $propName) {
                    $slotIndex = $propset[3];
                    $propertyType = $propset[2];
                    break;
                }
            }
            if (null === $slotIndex) {
                throw new \LogicException("clone-with reinit: property {$propName} not on class {$id}");
            }
            $slot = $this->propertySlotPtr($obj, $slotIndex);
            $entry = $this->propertyDefaults[$id][$slotIndex] ?? null;
            if (null !== $entry) {
                if (!empty($entry['emptyArray'])) {
                    $ht = HashTableHelper::alloc($this->context);
                    $emptyHt = new Variable(
                        $this->context,
                        Variable::TYPE_HASHTABLE,
                        Variable::KIND_VALUE,
                        $ht
                    );
                    $this->propertyStore($slot, $emptyHt, $entry['propertyType']);

                    return;
                }
                $constEntry = isset($entry['global'])
                    ? ['type' => $entry['type'], 'global' => $entry['global']]
                    : ['type' => $entry['type'], 'value' => $entry['value']];
                $var = $this->jitConstantFromEntry($constEntry);
                $this->propertyStore($slot, $var, $entry['propertyType']);

                return;
            }
            if (isset($this->runtimePropertyNewDefaults[$id][$slotIndex])) {
                $newClassId = $this->runtimePropertyNewDefaults[$id][$slotIndex];
                $child = $this->allocate($newClassId);
                if (!$this->hasConstructor($newClassId)) {
                    $this->markObjectConstructed($child);
                }
                $jitVar = new Variable(
                    $this->context,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $child
                );
                $this->propertyStore($slot, $jitVar, $propertyType ?? Variable::TYPE_OBJECT);

                return;
            }
            if (Variable::TYPE_HASHTABLE === $propertyType) {
                $ht = HashTableHelper::alloc($this->context);
                $emptyHt = new Variable(
                    $this->context,
                    Variable::TYPE_HASHTABLE,
                    Variable::KIND_VALUE,
                    $ht
                );
                $this->propertyStore($slot, $emptyHt, $propertyType);

                return;
            }
            if (Variable::TYPE_VALUE === $propertyType) {
                $valueType = $this->context->getTypeFromString('__value__');
                $heapVal = $this->context->memory->malloc($valueType);
                $nullVar = new Variable(
                    $this->context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $this->context->builder->pointerCast($heapVal, $this->context->getTypeFromString('__value__*'))
                );
                $this->propertyStore($slot, $nullVar, $propertyType);
            }
        }, 'clone_with_reinit_'.$propName);
    }

    /** Copy instance properties from initializer result object (lazy proxy, #4940). */
    public function copyInstancePropertiesFrom(PHPLLVM\Value $dest, PHPLLVM\Value $src, PHPLLVM\Value $classIdVal): void
    {
        $fn = \PHPCompiler\JIT\BasicBlockHelper::parentFunction($this->context);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('lazy_copy_props_done');
        $fail = $fn->appendBasicBlock('lazy_copy_props_fail');
        $check = $entry;
        $hasCase = false;
        foreach (array_keys($this->allClassNamesById()) as $id) {
            $hasCase = true;
            $case = $fn->appendBasicBlock('lazy_copy_props_'.$id);
            $next = $fn->appendBasicBlock('lazy_copy_try_'.$id);
            $this->context->builder->positionAtEnd($check);
            $expected = $this->context->constantFromInteger($id, 'int64');
            $match = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classIdVal, $expected);
            $this->context->builder->branchIf($match, $case, $next);
            $this->context->builder->positionAtEnd($case);
            $this->copyPropertySlots($dest, $src, $id, $done);
            $check = $next;
        }
        if (!$hasCase) {
            $this->context->builder->branch($fail);
        } else {
            $this->context->builder->positionAtEnd($check);
            $this->context->builder->branch($fail);
        }
        $this->context->builder->positionAtEnd($fail);
        $this->context->builder->call($this->context->lookupFunction('abort'));
        $this->context->builder->positionAtEnd($done);
    }

    /**
     * @param callable(int): void $body
     */
    private function dispatchByRuntimeClassId(PHPLLVM\Value $classIdVal, callable $body, string $tag): void
    {
        $fn = \PHPCompiler\JIT\BasicBlockHelper::parentFunction($this->context);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock($tag.'_done');
        $fail = $fn->appendBasicBlock($tag.'_fail');
        $check = $entry;
        $hasCase = false;
        foreach (array_keys($this->allClassNamesById()) as $id) {
            $hasCase = true;
            $case = $fn->appendBasicBlock($tag.'_'.$id);
            $next = $fn->appendBasicBlock($tag.'_try_'.$id);
            $this->context->builder->positionAtEnd($check);
            $expected = $this->context->constantFromInteger($id, 'int64');
            $match = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $classIdVal, $expected);
            $this->context->builder->branchIf($match, $case, $next);
            $this->context->builder->positionAtEnd($case);
            $body($id);
            $this->context->builder->branch($done);
            $check = $next;
        }
        if (!$hasCase) {
            $this->context->builder->branch($fail);
        } else {
            $this->context->builder->positionAtEnd($check);
            $this->context->builder->branch($fail);
        }
        $this->context->builder->positionAtEnd($fail);
        $this->context->builder->call($this->context->lookupFunction('abort'));
        $this->context->builder->positionAtEnd($done);
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
        foreach (['constructed', 'lazy_pending', 'lazy_ghost', 'lazy_init_index'] as $field) {
            $value = $this->context->builder->load(
                $this->context->builder->structGep($src, $map[$field])
            );
            $this->context->builder->store(
                $value,
                $this->context->builder->structGep($dest, $map[$field])
            );
        }
    }

    public function classNameForId(int $id): string
    {
        if (!isset($this->classIdToName[$id])) {
            throw new \LogicException("Unknown class id {$id}");
        }

        return $this->classIdToName[$id];
    }

    /** @return array<int, string> */
    public function registeredClassNamesById(): array
    {
        return $this->classIdToName;
    }

    public function classIdByName(string $name): ?int
    {
        $lc = strtolower($name);
        foreach ($this->classIdToName as $id => $className) {
            if (strtolower($className) === $lc) {
                return $id;
            }
        }

        return null;
    }

    /**
     * WeakMap backing __hashtable__ at property slot 0 (#3667).
     */
    public function weakMapBackingHashtable(Variable $obj): Variable
    {
        if (Variable::TYPE_OBJECT !== $obj->type) {
            throw new \LogicException('weakMapBackingHashtable requires __object__*');
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
     * property_exists() from a scope class — respects inheritance visibility (#4361).
     */
    public function propertyExistsFromScope(int $scopeClassId, string $property): bool
    {
        $lc = strtolower($property);
        $currentId = $scopeClassId;
        for ($depth = 0; $depth < 64; ++$depth) {
            foreach ($this->properties[$currentId] ?? [] as $propset) {
                if (strtolower($propset[1]) !== $lc) {
                    continue;
                }
                $declId = $this->instancePropertyDeclaringClassId[$currentId][$lc] ?? $currentId;

                return $this->propertyVisibleFromScopeId(
                    $scopeClassId,
                    $this->propertyVisibility($currentId, $propset[1]),
                    $declId
                );
            }
            if (isset($this->staticPropertyGlobals[$currentId][$lc])) {
                $meta = $this->staticPropertyVisibilityMeta($currentId, $property);
                if (null === $meta) {
                    return false;
                }

                return $this->propertyVisibleFromScopeId(
                    $scopeClassId,
                    $meta['visibility'],
                    $meta['declaringClassId']
                );
            }
            $parentLc = $this->parentClassLc($this->classNameForId($currentId));
            if (null === $parentLc) {
                return false;
            }
            $currentId = $this->lookup($parentLc);
        }

        return false;
    }

    private function propertyVisibleFromScopeId(int $scopeClassId, int $visibility, int $declaringClassId): bool
    {
        if (MethodVisibility::isPublic($visibility)) {
            return true;
        }
        if (($visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return $scopeClassId === $declaringClassId;
        }
        if (($visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return $this->isSameOrSubclassOfClassId($scopeClassId, $declaringClassId);
        }

        return true;
    }

    private function isSameOrSubclassOfClassId(int $classId, int $ancestorId): bool
    {
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            if ($currentId === $ancestorId) {
                return true;
            }
            $parentLc = $this->parentClassLc($this->classNameForId($currentId));
            if (null === $parentLc) {
                return false;
            }
            $currentId = $this->lookup($parentLc);
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

    /**
     * Class ids with declared instance properties — bounds get_object_vars() JIT dispatch (#4038).
     *
     * @return list<int>
     */
    public function classIdsWithInstanceProperties(): array
    {
        $ids = [];
        foreach ($this->classIdToName as $id => $_name) {
            if ($this->isExternalOnlyClass((int) $id)) {
                continue;
            }
            if ([] !== ($this->properties[$id] ?? [])) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Public static property compile-time defaults for get_class_vars() (#7420).
     *
     * @return array<string, array{type: int, value: int|float|bool|string|null}>
     */
    public function publicStaticPropertyDefaultEntries(int $classId): array
    {
        $entries = [];
        foreach ($this->orderedPublicStaticPropertyNames($classId) as $name) {
            $entry = $this->staticPropertyGlobals[$classId][$name] ?? null;
            if (null === $entry) {
                continue;
            }
            if (!MethodVisibility::isPublic($this->staticPropertyVisibility[$classId][$name] ?? \PHPCfg\Func::FLAG_PUBLIC)) {
                continue;
            }
            if (!empty($entry['typedWithoutDefault']) && null === ($entry['default'] ?? null)) {
                continue;
            }
            $default = $entry['default'] ?? null;
            if (null === $default) {
                continue;
            }
            $entries[$name] = [
                'type' => $entry['type'],
                'value' => $this->compileTimeValueFromVm($default),
            ];
        }

        return $entries;
    }

    /**
     * php-src add_class_vars: class-declared statics before trait/parent (#7417).
     *
     * @return list<string>
     */
    private function orderedPublicStaticPropertyNames(int $classId): array
    {
        $globals = $this->staticPropertyGlobals[$classId] ?? [];
        if ([] === $globals) {
            return [];
        }
        $composed = [];
        $own = [];
        foreach (array_keys($globals) as $name) {
            $declId = $this->staticPropertyDeclaringClassId[$classId][$name] ?? $classId;
            if ($declId !== $classId) {
                $composed[] = $name;
            } else {
                $own[] = $name;
            }
        }

        return array_merge($own, $composed);
    }

    /**
     * Compile-time property default metadata for get_class_vars() (#3159).
     *
     * @return array<int, array{propertyType: int, type: int, value: int|float|bool|string|null}>
     */
    public function propertyDefaultEntries(int $classId): array
    {
        return $this->propertyDefaults[$classId] ?? [];
    }

    public function propertySlotIndex(int $classId, string $name): ?int
    {
        $nameId = $this->propNameMap[$name] ?? null;
        if (null === $nameId) {
            return null;
        }
        foreach ($this->properties[$classId] ?? [] as $propset) {
            if ($propset[0] === $nameId) {
                return $propset[3];
            }
        }

        return null;
    }

    /**
     * Resolve [declaring class id, slot index] walking the extends chain (#4614, zend_object_handlers.c).
     *
     * @return array{0: int, 1: int}|null
     */
    public function resolvePropertySlot(string $className, string $propName): ?array
    {
        $currentLc = strtolower(ltrim($className, '\\'));
        for ($depth = 0; $depth < 64; ++$depth) {
            if (!isset($this->classes[$currentLc])) {
                break;
            }
            $classId = $this->classes[$currentLc];
            $slotIndex = $this->propertySlotIndex($classId, $propName);
            if (null !== $slotIndex) {
                return [$classId, $slotIndex];
            }
            $parent = $this->classParentLc[$currentLc] ?? null;
            if (null === $parent) {
                break;
            }
            $currentLc = $parent;
        }

        return null;
    }

    public function propertySlotHasCompileTimeDefault(int $classId, int $slotIndex): bool
    {
        return isset($this->propertyDefaults[$classId][$slotIndex]);
    }

    public function markPropertyAllowsNull(int $classId, string $name): void
    {
        foreach ($this->properties[$classId] ?? [] as $propset) {
            if ($propset[1] !== $name) {
                continue;
            }
            $this->propertyAllowsNullSlots[$classId][$propset[3]] = true;

            return;
        }
    }

    public function propertySlotAllowsNull(int $classId, int $slotIndex): bool
    {
        return isset($this->propertyAllowsNullSlots[$classId][$slotIndex]);
    }

    /** True when the slot stores a typed __value__ property (#4912). */
    public function propertySlotIsTypedValue(int $classId, int $slotIndex): bool
    {
        foreach ($this->properties[$classId] ?? [] as $propset) {
            if ($propset[3] === $slotIndex) {
                return Variable::TYPE_VALUE === $propset[2];
            }
        }

        return false;
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
        if ('reflectionattribute' === $lcname) {
            $this->seedExternalClassConstants($id, [
                'is_instanceof' => \PHPCompiler\VM\ReflectionSupport::REFLECTION_ATTRIBUTE_IS_INSTANCEOF,
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

    /**
     * Internal zend classes whose instance storage must not leak via get_object_vars() from global scope (#10719).
     *
     * @return list<int>
     */
    public function internalClassIdsForObjectVarsGuard(): array
    {
        $ids = [];
        foreach (array_keys($this->externalOnlyClassIds) as $id) {
            if (!isset($this->allowsDynamicPropertiesClassIds[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
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
        if ('stdclass' === $lcname) {
            $this->allowsDynamicPropertiesClassIds[$id] = true;
        }
        $this->ensureExternalClassConstants($id, $lcname);
        $this->seedExternalClassProperties($id, $lcname);
        if ('reflectionattribute' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'args', Variable::TYPE_HASHTABLE);
        }
        if ('reflectionclass' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
        }
        if ('reflectionmethod' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
            $this->defineProperty($id, 'method', Variable::TYPE_STRING);
        }
        if ('reflectionproperty' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
            $this->defineProperty($id, 'property', Variable::TYPE_STRING);
        }
        if ('reflectionconstant' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
            $this->defineProperty($id, 'constant', Variable::TYPE_STRING);
        }
        if ('reflectionenum' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
        }
        if ('reflectionenumunitcase' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
            $this->defineProperty($id, 'case', Variable::TYPE_STRING);
        }
        if ('reflectionenumbackedcase' === $lcname) {
            $this->setClassParentName('ReflectionEnumBackedCase', 'ReflectionEnumUnitCase');
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
            $this->defineProperty($id, 'case', Variable::TYPE_STRING);
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
            $this->defineProperty($id, FiberHelper::TARGET_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::BOUND_THIS_PROPERTY, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::BOUND_SCOPE_PROPERTY, Variable::TYPE_STRING);
        }
        if ('fiber' === $lcname) {
            $this->defineProperty($id, FiberHelper::TARGET_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, FiberHelper::STATE_PROPERTY, Variable::TYPE_NATIVE_LONG);
            foreach (['suspend', 'getcurrent'] as $fiberStaticMethod) {
                $this->defineMethodVisibility(
                    $id,
                    $fiberStaticMethod,
                    \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
                );
            }
        }
        if ('generator' === $lcname) {
            $this->defineProperty($id, GeneratorHelper::TARGET_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, GeneratorHelper::STATE_PROPERTY, Variable::TYPE_NATIVE_LONG);
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, ['Iterator']);
        }
        if ('splobjectstorage' === $lcname) {
            $this->splObjectStorageClassId = $id;
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
        }
        if ('sensitiveparametervalue' === $lcname) {
            // Empty marker class for #[\SensitiveParameter] trace redaction (#3351, #4621).
        }
        if ('weakreference' === $lcname) {
            $this->weakReferenceClassId = $id;
            $this->defineProperty($id, '__weak_target', Variable::TYPE_VALUE);
            $this->defineMethodVisibility(
                $id,
                'create',
                \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
            );
        }
        if ('weakmap' === $lcname) {
            $this->weakMapClassId = $id;
            $this->defineProperty($id, '__weak_map', Variable::TYPE_HASHTABLE);
            $this->setClassInterfaces($displayName, ['arrayaccess', 'countable']);
        }
        if ('streambucket' === $lcname) {
            // ext/standard/streams.c — bucket resource handle + buffer string (#6323, #7089).
            $this->defineProperty($id, 'bucket', Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, 'data', Variable::TYPE_STRING);
            unset($this->externalOnlyClassIds[$id]);
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
        if ('connectionstatus' === $lcname) {
            $this->enums[$lcname] = true;
            $this->setEnumBackedType($id, 'int');
            foreach ([
                'Normal' => \PHPCompiler\ext\standard\VmConnection::NORMAL,
                'Aborted' => \PHPCompiler\ext\standard\VmConnection::ABORTED,
                'Timeout' => \PHPCompiler\ext\standard\VmConnection::TIMEOUT,
            ] as $caseName => $value) {
                $backing = new VMVariable();
                $backing->int($value);
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
        if ('roundingmode' === $lcname) {
            $this->enums[$lcname] = true;
            foreach ([
                'HalfAwayFromZero',
                'HalfTowardsZero',
                'HalfEven',
                'HalfOdd',
                'TowardsZero',
                'AwayFromZero',
                'NegativeInfinity',
                'PositiveInfinity',
            ] as $caseName) {
                $backing = new VMVariable();
                $backing->null();
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
        if ('parseurl' === $lcname) {
            $this->enums[$lcname] = true;
            $this->setEnumBackedType($id, 'int');
            foreach ([
                'Scheme' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_SCHEME,
                'Host' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_HOST,
                'Port' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_PORT,
                'User' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_USER,
                'Pass' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_PASS,
                'Path' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_PATH,
                'Query' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_QUERY,
                'Fragment' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_FRAGMENT,
            ] as $caseName => $value) {
                $backing = new VMVariable();
                $backing->int($value);
                $this->defineEnumCaseConst($id, $caseName, $backing);
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
        if (str_starts_with($lcClass, 'phpcompiler\\vm\\context')) {
            if (in_array($lcName, [
                'functions',
                'classes',
                'classaliases',
                'enums',
                'classautoloaders',
                'splautoloadcallbacks',
                'loadedcompileunits',
                'constants',
                'superglobalvars',
                'globalvars',
                'functionstaticvars',
                'functionstaticinitialized',
                'foreachiterators',
                'objectpropertyiterators',
                'weakmapiterators',
                'activetryhandlerframes',
                'trymergeblockids',
                'foreachobjectadvance',
                'foreachinvalidslots',
                'deferredtraituses',
                'deferredclassconstants',
                'completedfinallyhandlers',
                'clirequestargv',
                'propertyhookregistry',
            ], true)) {
                return Variable::TYPE_HASHTABLE;
            }
            if (in_array($lcName, [
                'runtime',
                'errors',
                'scriptstack',
                'exceptionhandlers',
                'executionlimits',
            ], true)) {
                return Variable::TYPE_OBJECT;
            }
        }
        if (str_starts_with($lcClass, 'phpcompiler\\compiler')) {
            if (in_array($lcName, ['modules', 'compilequeue'], true)) {
                return Variable::TYPE_HASHTABLE;
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

    public function defineMethodVisibility(int $classId, string $methodLc, int $visibilityFlags, ?string $displayName = null): void
    {
        $methodLc = strtolower($methodLc);
        $this->methodVisibility[$classId][$methodLc] = $visibilityFlags;
        if (null !== $displayName) {
            $this->methodDisplayNames[$classId][$methodLc] = $displayName;
        }
    }

    /**
     * Method names visible on a class (including inherited), filtered by visibility bitmask (#3118).
     *
     * @return list<string>
     */
    public function allMethodNamesForClassId(int $classId, int $filter): array
    {
        $classLc = $this->classLcForId($classId);
        if (null === $classLc) {
            return [];
        }

        $chain = [];
        $currentLc = $classLc;
        while (null !== $currentLc) {
            array_unshift($chain, $currentLc);
            $currentLc = $this->classParentLc[$currentLc] ?? null;
        }

        /** @var array<string, string> method lc => display name */
        $seen = [];
        foreach ($chain as $lc) {
            if (!isset($this->classes[$lc])) {
                continue;
            }
            $id = $this->classes[$lc];
            foreach ($this->methodVisibility[$id] ?? [] as $methodLc => $vis) {
                if (0 !== ($filter & 7) && 0 === ($vis & $filter & 7)) {
                    continue;
                }
                $seen[$methodLc] = $this->methodDisplayNames[$id][$methodLc] ?? $methodLc;
            }
        }

        return array_values($seen);
    }

    public function definePropertyVisibility(int $classId, string $name, int $visibilityFlags): void
    {
        $this->propertyVisibility[$classId][strtolower($name)] = $visibilityFlags;
    }

    public function definePropertySetVisibility(int $classId, string $name, int $setVisibilityFlags): void
    {
        if (0 !== $setVisibilityFlags) {
            $this->propertySetVisibility[$classId][strtolower($name)] = $setVisibilityFlags;
        }
    }

    public function definePropertyGetVisibility(int $classId, string $name, int $getVisibilityFlags): void
    {
        if (0 !== $getVisibilityFlags) {
            $this->propertyGetVisibility[$classId][strtolower($name)] = $getVisibilityFlags;
        }
    }

    public function propertyVisibility(int $classId, string $name): int
    {
        return $this->propertyVisibility[$classId][strtolower($name)] ?? \PHPCfg\Func::FLAG_PUBLIC;
    }

    public function propertySetVisibility(int $classId, string $name): int
    {
        return $this->propertySetVisibility[$classId][strtolower($name)] ?? 0;
    }

    public function propertyGetVisibility(int $classId, string $name): int
    {
        return $this->propertyGetVisibility[$classId][strtolower($name)] ?? 0;
    }

    public function defineStaticPropertySetVisibility(int $classId, string $name, int $setVisibilityFlags): void
    {
        if (0 !== $setVisibilityFlags) {
            $this->staticPropertySetVisibility[$classId][strtolower($name)] = $setVisibilityFlags;
        }
    }

    public function defineStaticPropertyGetVisibility(int $classId, string $name, int $getVisibilityFlags): void
    {
        if (0 !== $getVisibilityFlags) {
            $this->staticPropertyGetVisibility[$classId][strtolower($name)] = $getVisibilityFlags;
        }
    }

    public function staticPropertySetVisibility(int $classId, string $name): int
    {
        return $this->staticPropertySetVisibility[$classId][strtolower($name)] ?? 0;
    }

    public function staticPropertyGetVisibility(int $classId, string $name): int
    {
        return $this->staticPropertyGetVisibility[$classId][strtolower($name)] ?? 0;
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

    /**
     * After DECLARE_PROPERTY lowering, register dynamic writes so `new` allocates enough slots (#5111).
     */
    public function definePendingUndeclaredInstanceProperties(int $classId, string $className): void
    {
        if ('' === $className) {
            return;
        }
        $pending = $this->context->jitUndeclaredInstancePropertyWrites[strtolower(ltrim($className, '\\'))] ?? [];
        foreach ($pending as $propName) {
            if ($this->hasProperty($classId, $propName)) {
                continue;
            }
            // User classes: native slots avoid VALUE-box propertyStore IR that segfaults MCJIT (#5111).
            $jitType = $this->isExternalOnlyClass($classId)
                ? $this->externalPropertyJitType($className, $propName)
                : Variable::TYPE_NATIVE_LONG;
            $this->defineProperty($classId, $propName, $jitType);
        }
    }

    /**
     * @param list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}> $arms
     */
    public function definePropertyDnfArms(int $classId, string $name, array $arms): void
    {
        if ([] === $arms) {
            return;
        }
        $this->propertyDnfArms[$classId][strtolower($name)] = $arms;
    }

    public function defineStaticPropertyDnfArms(int $classId, string $name, array $arms): void
    {
        if ([] === $arms) {
            return;
        }
        $this->staticPropertyDnfArms[$classId][strtolower($name)] = $arms;
    }

    /**
     * @return list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>|null
     */
    public function dnfArmsForProperty(int $classId, string $name): ?array
    {
        return $this->propertyDnfArms[$classId][strtolower($name)] ?? null;
    }

    /**
     * @return list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>|null
     */
    public function dnfArmsForStaticProperty(int $classId, string $name): ?array
    {
        return $this->staticPropertyDnfArms[$classId][strtolower($name)] ?? null;
    }

    public function definePropertyRuntimeNewDefault(int $classId, string $name, string $newClassName): void
    {
        $newClassId = $this->lookup($newClassName);
        foreach ($this->properties[$classId] as $propset) {
            if ($propset[1] !== $name) {
                continue;
            }
            $this->runtimePropertyNewDefaults[$classId][$propset[3]] = $newClassId;

            return;
        }
        throw new \LogicException("Property {$name} not defined for class {$classId}");
    }

    public function definePropertyDefault(int $classId, string $name, VMVariable $value): void
    {
        if (VMVariable::TYPE_ARRAY === $value->type) {
            foreach ($this->properties[$classId] as $propset) {
                if ($propset[1] !== $name) {
                    continue;
                }
                // Per-instance empty array default (Zend zend_objects.c; bootstrap array_value_box).
                $this->propertyDefaults[$classId][$propset[3]] = [
                    'propertyType' => $propset[2],
                    'type' => Variable::TYPE_HASHTABLE,
                    'emptyArray' => true,
                ];

                return;
            }
            throw new \LogicException("Property {$name} not defined for class {$classId}");
        }
        foreach ($this->properties[$classId] as $propset) {
            if ($propset[1] !== $name) {
                continue;
            }
            if (EnumCaseSupport::isEnumCaseVariable($value)) {
                $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
                if (null === $enumClass) {
                    throw new \LogicException('Enum case property default requires enum class');
                }
                $enumClassId = $this->lookup(strtolower($enumClass->name));
                $caseKey = strtolower(EnumCaseSupport::enumCaseNameForVariable($value));
                $globalName = $this->ensureEnumCaseSingletonGlobal($enumClassId, $caseKey);
                $this->propertyDefaults[$classId][$propset[3]] = [
                    'propertyType' => $propset[2],
                    'type' => Variable::TYPE_OBJECT,
                    'global' => $globalName,
                ];

                return;
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

    public function defineClassConstVisibility(int $classId, string $name, int $visibilityFlags): void
    {
        $this->constVisibility[$classId][strtolower($name)] = ClassConstVisibility::mask($visibilityFlags);
    }

    public function constVisibility(int $classId, string $name): int
    {
        return $this->constVisibility[$classId][strtolower($name)] ?? \PHPCfg\Func::FLAG_PUBLIC;
    }

    public function defineClassConst(int $classId, string $name, VMVariable $value): void
    {
        $key = strtolower($name);
        $this->classConstDisplayNames[$classId][$key] = $name;
        if (VMVariable::TYPE_ARRAY === $value->type) {
            $table = $value->toArray();
            if (!$table instanceof \PHPCompiler\VM\HashTable) {
                throw new \LogicException('Class constant array must be a HashTable');
            }
            $globalName = 'php_compiler_class_const_ht_'.$classId.'_'.$key;
            $htPtrType = $this->context->getTypeFromString('__hashtable__*');
            $global = $this->context->module->addGlobal($htPtrType, $globalName);
            $global->setInitializer($htPtrType->constNull());
            $this->classConstHashtableGlobals[$globalName] = $global;
            $this->context->emitInInit(function (Context $ctx) use ($table, $global): void {
                $htVar = HashTableHelper::variableFromVmHashTable($ctx, $table);
                $htPtr = $ctx->helper->loadValue($htVar);
                $ctx->refcount->addref($htPtr);
                $ctx->builder->store($htPtr, $global);
            });
            $entry = [
                'type' => Variable::TYPE_HASHTABLE,
                'global' => $globalName,
            ];
            $this->rejectIncompatibleTraitClassConstOverride($classId, $key, $name, $entry);
            unset($this->traitConstSources[$classId][$key]);
            $this->classConstants[$classId][$key] = $entry;

            return;
        }
        if (VMVariable::TYPE_OBJECT === $value->type) {
            $object = $value->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                $enumClassLc = strtolower($object->class->name);
                $caseKey = strtolower((string) ($object->enumCaseName ?? ''));
                $this->defineClassConstEnumCaseRef($classId, $key, $this->lookup($enumClassLc), $caseKey);

                return;
            }
            $objClass = strtolower($object->class->name);
            $jitClassId = $this->lookup($objClass);
            $globalName = 'php_compiler_class_const_obj_'.$classId.'_'.$key;
            $objPtrType = $this->context->getTypeFromString('__object__*');
            $global = $this->context->module->addGlobal($objPtrType, $globalName);
            $global->setInitializer($objPtrType->constNull());
            $this->classConstObjectGlobals[$globalName] = $global;
            $this->context->emitInInit(function (Context $ctx) use ($jitClassId, $global): void {
                $alloc = $this->allocateClassConstantObject($jitClassId);
                $ctx->builder->store($alloc, $global);
            });
            $entry = [
                'type' => Variable::TYPE_OBJECT,
                'global' => $globalName,
            ];
            $this->rejectIncompatibleTraitClassConstOverride($classId, $key, $name, $entry);
            unset($this->traitConstSources[$classId][$key]);
            $this->classConstants[$classId][$key] = $entry;

            return;
        }
        $entry = [
            'type' => Variable::fromVMVariable($value->type),
            'value' => $this->compileTimeValueFromVm($value),
        ];
        $this->rejectIncompatibleTraitClassConstOverride($classId, $key, $name, $entry);
        unset($this->traitConstSources[$classId][$key]);
        $this->classConstants[$classId][$key] = $entry;
    }

    public function defineClassConstEnumCaseRef(
        int $holdingClassId,
        string $constName,
        int $enumClassId,
        string $caseKey
    ): void {
        $constKey = strtolower($constName);
        $this->classConstDisplayNames[$holdingClassId][$constKey] = $constName;
        $caseKey = strtolower($caseKey);
        if (!$this->isEnumClassId($enumClassId)) {
            throw new \LogicException('Class constant enum case reference requires an enum class id');
        }
        if ('' === $caseKey || !isset($this->classConstants[$enumClassId][$caseKey])) {
            $enumLc = $this->classNameForId($enumClassId);
            throw new \LogicException("Unknown enum case for class constant: {$enumLc}::{$caseKey}");
        }
        $globalName = $this->ensureEnumCaseSingletonGlobal($enumClassId, $caseKey);
        $entry = [
            'type' => Variable::TYPE_OBJECT,
            'global' => $globalName,
        ];
        $this->rejectIncompatibleTraitClassConstOverride($holdingClassId, $constKey, $constName, $entry);
        unset($this->traitConstSources[$holdingClassId][$constKey]);
        $this->classConstants[$holdingClassId][$constKey] = $entry;
    }

    /**
     * Module-global enum case singleton used by class const / property default inits (#5891).
     */
    public function ensureEnumCaseSingletonGlobal(int $enumClassId, string $caseKey): string
    {
        $caseKey = strtolower($caseKey);
        if (!$this->isEnumClassId($enumClassId)) {
            throw new \LogicException('Enum case singleton requires an enum class id');
        }
        if ('' === $caseKey || !isset($this->classConstants[$enumClassId][$caseKey])) {
            $enumLc = $this->classNameForId($enumClassId);
            throw new \LogicException("Unknown enum case singleton: {$enumLc}::{$caseKey}");
        }
        $globalName = EnumCasePropertyJitHelper::singletonGlobalName($enumClassId, $caseKey);
        if (!isset($this->classConstObjectGlobals[$globalName])) {
            $objPtrType = $this->context->getTypeFromString('__object__*');
            $global = $this->context->module->addGlobal($objPtrType, $globalName);
            $global->setInitializer($objPtrType->constNull());
            $this->classConstObjectGlobals[$globalName] = $global;
            $canonicalName = $this->enumCaseCanonicalName($enumClassId, $caseKey);
            $backingEntry = $this->classConstants[$enumClassId][$caseKey];
            $this->context->emitInInit(function (Context $ctx) use (
                $enumClassId,
                $canonicalName,
                $backingEntry,
                $global
            ): void {
                $alloc = $this->allocateClassConstantEnumCase(
                    $enumClassId,
                    $canonicalName,
                    $this->jitConstantFromEntry($backingEntry)
                );
                $ctx->builder->store($alloc, $global);
            });
        }

        return $globalName;
    }

    public function embedClassConstArrayVmElementAtIndex(
        Context $context,
        PHPLLVM\Value $ht,
        PHPLLVM\Value $index,
        VMVariable $resolved
    ): void {
        $resolved = $resolved->resolveIndirect();
        if (VMVariable::TYPE_ENUM_CASE === $resolved->type
            || (VMVariable::TYPE_OBJECT === $resolved->type
                && \PHPCompiler\VM\EnumCaseSupport::isEnumCase($resolved->toObject()))) {
            $enumClass = \PHPCompiler\VM\EnumCaseSupport::enumClassForCaseVariable($resolved);
            if (null === $enumClass) {
                throw new \LogicException('Class constant array enum case requires enum class');
            }
            $caseKey = strtolower(\PHPCompiler\VM\EnumCaseSupport::enumCaseNameForVariable($resolved));
            $enumClassId = $this->lookup(strtolower($enumClass->name));
            $globalName = $this->ensureEnumCaseSingletonGlobal($enumClassId, $caseKey);
            $obj = $context->builder->load($this->classConstObjectGlobals[$globalName]);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectAt'),
                $ht,
                $index,
                $obj
            );

            return;
        }
        if (VMVariable::TYPE_OBJECT === $resolved->type) {
            $object = $resolved->toObject();
            $jitClassId = $this->lookup(strtolower($object->class->name));
            $obj = $this->allocateClassConstantObject($jitClassId);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectAt'),
                $ht,
                $index,
                $obj
            );

            return;
        }
        throw new \LogicException(
            'Unsupported class constant array element type for JIT: '
            .Variable::getStringType(Variable::fromVMVariable($resolved->type))
        );
    }

    public function embedClassConstArrayVmElementAtStringKey(
        Context $context,
        PHPLLVM\Value $ht,
        PHPLLVM\Value $stringKey,
        VMVariable $resolved
    ): void {
        $resolved = $resolved->resolveIndirect();
        if (VMVariable::TYPE_ENUM_CASE === $resolved->type
            || (VMVariable::TYPE_OBJECT === $resolved->type
                && \PHPCompiler\VM\EnumCaseSupport::isEnumCase($resolved->toObject()))) {
            $enumClass = \PHPCompiler\VM\EnumCaseSupport::enumClassForCaseVariable($resolved);
            if (null === $enumClass) {
                throw new \LogicException('Class constant array enum case requires enum class');
            }
            $caseKey = strtolower(\PHPCompiler\VM\EnumCaseSupport::enumCaseNameForVariable($resolved));
            $enumClassId = $this->lookup(strtolower($enumClass->name));
            $globalName = $this->ensureEnumCaseSingletonGlobal($enumClassId, $caseKey);
            $obj = $context->builder->load($this->classConstObjectGlobals[$globalName]);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectKeyObject'),
                $ht,
                $stringKey,
                $obj
            );

            return;
        }
        if (VMVariable::TYPE_OBJECT === $resolved->type) {
            $object = $resolved->toObject();
            $jitClassId = $this->lookup(strtolower($object->class->name));
            $obj = $this->allocateClassConstantObject($jitClassId);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectKeyObject'),
                $ht,
                $stringKey,
                $obj
            );

            return;
        }
        throw new \LogicException(
            'Unsupported class constant array element type for JIT: '
            .Variable::getStringType(Variable::fromVMVariable($resolved->type))
        );
    }

    public function inheritInterfaceConstants(int $classId, string $className): void
    {
        $classLc = strtolower(ltrim($className, '\\'));
        foreach ($this->allInterfacesForClassLc($classLc) as $ifaceLc) {
            if ($ifaceLc === $classLc) {
                continue;
            }
            $ifaceId = $this->lookup($ifaceLc);
            if (!isset($this->classConstants[$ifaceId])) {
                continue;
            }
            foreach ($this->classConstants[$ifaceId] as $name => $entry) {
                if (!isset($this->classConstants[$classId][$name])) {
                    $this->classConstants[$classId][$name] = $entry;
                    if (isset($this->constVisibility[$ifaceId][$name])) {
                        $this->constVisibility[$classId][$name] = $this->constVisibility[$ifaceId][$name];
                    }
                }
            }
        }
    }

    /** Merge a newly declared interface into prior implementors (#9302). */
    public function propagateInterfaceConstantsToImplementors(string $ifaceName): void
    {
        $ifaceLc = strtolower(ltrim($ifaceName, '\\'));
        foreach ($this->classInterfacesLc as $classLc => $ifaces) {
            if (!in_array($ifaceLc, $ifaces, true)) {
                continue;
            }
            $classId = $this->lookup($classLc);
            $this->inheritInterfaceConstants($classId, $this->classNameForId($classId));
        }
    }

    /** Apply interface asymmetric set visibility to class properties (#4876). */
    public function inheritInterfacePropertySetVisibility(int $classId, string $className): void
    {
        $classLc = strtolower(ltrim($className, '\\'));
        foreach ($this->allInterfacesForClassLc($classLc) as $ifaceLc) {
            if ($ifaceLc === $classLc) {
                continue;
            }
            $ifaceId = $this->lookup($ifaceLc);
            foreach ($this->properties[$ifaceId] ?? [] as $propset) {
                $name = $propset[1];
                $setVis = $this->propertySetVisibility($ifaceId, $name);
                if (0 === $setVis) {
                    continue;
                }
                if (null !== $this->propertySlotIndex($classId, $name)) {
                    $this->definePropertySetVisibility($classId, $name, $setVis);
                }
            }
        }
    }

    public function markTraitClass(string $classLc): void
    {
        $this->traitClassLcs[strtolower(ltrim($classLc, '\\'))] = true;
    }

    public function markAttributeClass(string $classLc): void
    {
        $this->attributeClassLcs[strtolower(ltrim($classLc, '\\'))] = true;
    }

    public function isTraitClass(string $classLc): bool
    {
        $this->ensureLazyGhostBuiltinTrait();

        return isset($this->traitClassLcs[strtolower(ltrim($classLc, '\\'))]);
    }

    /**
     * Lowercase trait names from DECLARE_TRAIT (trait_exists() JIT, #2312).
     *
     * @return list<string>
     */
    public function traitClassLowerNames(): array
    {
        return array_keys($this->traitClassLcs);
    }

    public function recordTraitMethodSource(int $classId, string $methodLc, string $traitLc): void
    {
        $this->classTraitMethodSources[$classId][strtolower($methodLc)] = strtolower(ltrim($traitLc, '\\'));
    }

    public function traitMethodSource(int $classId, string $methodLc): ?string
    {
        return $this->classTraitMethodSources[$classId][strtolower($methodLc)] ?? null;
    }

    public function recordTraitMethodBlock(int $traitId, string $methodLc, Block $block): void
    {
        $this->traitMethodBlocks[$traitId][strtolower($methodLc)] = $block;
    }

    public function traitMethodBlock(int $traitId, string $methodLc): ?Block
    {
        return $this->traitMethodBlocks[$traitId][strtolower($methodLc)] ?? null;
    }

    public function inheritTraitConstants(int $classId, int $traitId, string $traitName): void
    {
        if (!isset($this->classConstants[$traitId])) {
            return;
        }
        $className = $this->classNameForId($classId);
        foreach ($this->classConstants[$traitId] as $name => $entry) {
            if (isset($this->classConstants[$classId][$name])) {
                if ($this->classConstEntriesIdentical($this->classConstants[$classId][$name], $entry)) {
                    continue;
                }
                $prevTrait = $this->traitConstSources[$classId][$name] ?? $className;
                $constDisplay = $this->classConstDisplayNames[$classId][$name]
                    ?? $this->classConstDisplayNames[$traitId][$name]
                    ?? $name;
                throw new \LogicException(sprintf(
                    '%s and %s define the same constant (%s) in the composition of %s. '
                    .'However, the definition differs and is considered incompatible. Class was composed',
                    $prevTrait,
                    $traitName,
                    $constDisplay,
                    $className
                ));
            }
            $this->classConstants[$classId][$name] = $entry;
            $this->traitConstSources[$classId][$name] = $traitName;
            if (isset($this->classConstDisplayNames[$traitId][$name])) {
                $this->classConstDisplayNames[$classId][$name] = $this->classConstDisplayNames[$traitId][$name];
            }
            if (isset($this->constVisibility[$traitId][$name])) {
                $this->constVisibility[$classId][$name] = $this->constVisibility[$traitId][$name];
            }
        }
    }

    public function inheritTraitStaticProperties(int $classId, int $traitId, string $traitName): void
    {
        if (!isset($this->staticPropertyGlobals[$traitId])) {
            return;
        }
        $className = $this->classNameForId($classId);
        foreach ($this->staticPropertyGlobals[$traitId] as $name => $entry) {
            if (isset($this->staticPropertyGlobals[$classId][$name])) {
                $prevTraitId = $this->staticPropertyDeclaringClassId[$classId][$name] ?? $classId;
                throw new \LogicException(TraitCompositionConflictMessage::incompatibleProperty(
                    $this->classNameForId($prevTraitId),
                    $traitName,
                    $name,
                    $className
                ));
            }
            $this->defineStaticProperty(
                $classId,
                $name,
                $entry['type'],
                $entry['default'] ?? null,
                null,
                !empty($entry['typedWithoutDefault'])
            );
            if (isset($this->staticPropertyVisibility[$traitId][$name])) {
                $this->staticPropertyVisibility[$classId][$name] = $this->staticPropertyVisibility[$traitId][$name];
            }
            if (isset($this->staticPropertySetVisibility[$traitId][$name])) {
                $this->staticPropertySetVisibility[$classId][$name] = $this->staticPropertySetVisibility[$traitId][$name];
            }
            if (isset($this->staticPropertyGetVisibility[$traitId][$name])) {
                $this->staticPropertyGetVisibility[$classId][$name] = $this->staticPropertyGetVisibility[$traitId][$name];
            }
            if (isset($this->staticPropertyDeclaringClassId[$traitId][$name])) {
                $this->staticPropertyDeclaringClassId[$classId][$name]
                    = $this->staticPropertyDeclaringClassId[$traitId][$name];
            }
            $arms = $this->dnfArmsForStaticProperty($traitId, $name);
            if (null !== $arms) {
                $this->defineStaticPropertyDnfArms($classId, $name, $arms);
            }
        }
    }

    public function inheritTraitInstanceProperties(int $classId, int $traitId, string $traitName): void
    {
        $className = $this->classNameForId($classId);
        foreach ($this->properties[$traitId] ?? [] as $propset) {
            $name = $propset[1];
            $nameLc = strtolower($name);
            foreach ($this->properties[$classId] ?? [] as $existing) {
                if (strtolower($existing[1]) === $nameLc) {
                    $prevTraitId = $this->instancePropertyDeclaringClassId[$classId][$nameLc] ?? $classId;
                    throw new \LogicException(TraitCompositionConflictMessage::incompatibleProperty(
                        $this->classNameForId($prevTraitId),
                        $traitName,
                        $name,
                        $className
                    ));
                }
            }
            $type = $propset[2];
            $this->defineProperty($classId, $name, $type);
            $this->instancePropertyDeclaringClassId[$classId][$nameLc] = $traitId;
            $this->definePropertyVisibility($classId, $name, $this->propertyVisibility($traitId, $name));
            $setVis = $this->propertySetVisibility($traitId, $name);
            if (0 !== $setVis) {
                $this->definePropertySetVisibility($classId, $name, $setVis);
            }
            if ($this->isPropertyReadonly($traitId, $name)) {
                $this->markPropertyReadonly($classId, $name);
            }
            $arms = $this->dnfArmsForProperty($traitId, $name);
            if (null !== $arms) {
                $this->definePropertyDnfArms($classId, $name, $arms);
            }
            $classSlot = $this->propertySlotIndex($classId, $name);
            if (null === $classSlot) {
                throw new \LogicException("Property {$name} not defined for class {$classId}");
            }
            if (isset($this->propertyDefaults[$traitId])) {
                foreach ($this->propertyDefaults[$traitId] as $slotIndex => $entry) {
                    if (($this->properties[$traitId][$slotIndex][1] ?? '') !== $name) {
                        continue;
                    }
                    $this->propertyDefaults[$classId][$classSlot] = $entry;
                    break;
                }
            }
            if (isset($this->runtimePropertyNewDefaults[$traitId])) {
                foreach ($this->runtimePropertyNewDefaults[$traitId] as $slotIndex => $newClassId) {
                    if (($this->properties[$traitId][$slotIndex][1] ?? '') !== $name) {
                        continue;
                    }
                    $this->runtimePropertyNewDefaults[$classId][$classSlot] = $newClassId;
                    break;
                }
            }
        }
    }

    public function inheritParentStaticProperties(int $childId, string $parentLc): void
    {
        if (!$this->hasDeclaredClass($parentLc)) {
            return;
        }
        $parentId = $this->lookup($parentLc);
        if (!isset($this->staticPropertyGlobals[$parentId])) {
            return;
        }
        foreach ($this->staticPropertyGlobals[$parentId] as $name => $entry) {
            if (!isset($this->staticPropertyGlobals[$childId][$name])) {
                $this->defineStaticProperty(
                    $childId,
                    $name,
                    $entry['type'],
                    $entry['default'] ?? null,
                    null,
                    !empty($entry['typedWithoutDefault'])
                );
                if (isset($this->staticPropertyVisibility[$parentId][$name])) {
                    $this->staticPropertyVisibility[$childId][$name] = $this->staticPropertyVisibility[$parentId][$name];
                }
                if (isset($this->staticPropertySetVisibility[$parentId][$name])) {
                    $this->staticPropertySetVisibility[$childId][$name] = $this->staticPropertySetVisibility[$parentId][$name];
                }
                if (isset($this->staticPropertyGetVisibility[$parentId][$name])) {
                    $this->staticPropertyGetVisibility[$childId][$name] = $this->staticPropertyGetVisibility[$parentId][$name];
                }
                if (isset($this->staticPropertyDeclaringClassId[$parentId][$name])) {
                    $this->staticPropertyDeclaringClassId[$childId][$name]
                        = $this->staticPropertyDeclaringClassId[$parentId][$name];
                }
            }
        }
    }

    public function resolveClassId(Operand $classOp): int
    {
        if (!$classOp instanceof Literal) {
            throw new \LogicException('JIT only supports constant named classes for class const fetch');
        }
        $name = strtolower($classOp->value);
        if ('self' === $name) {
            if ('' === $this->context->scope->className) {
                PseudoClassScope::fatalInGlobalScope('self');
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
            PseudoClassScope::fatalInGlobalScope('static');
        }
        if ('parent' === $name) {
            if ('' === $this->context->scope->className) {
                PseudoClassScope::fatalInGlobalScope('parent');
            }
            $parentLc = $this->parentClassLc($this->context->scope->className);
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $this->lookup($parentLc);
        }

        return $this->lookup($classOp->value);
    }

    public function classConstFetch(int $classId, string $constName, ?Block $block = null): Variable
    {
        $this->emitDirectTraitConstAccessErrorIfNeeded($classId, $constName, $block);
        $key = strtolower($constName);
        if (!isset($this->classConstants[$classId][$key])) {
            throw new \LogicException("Undefined class constant: {$constName}");
        }

        if ($this->isEnumClassId($classId)) {
            return $this->jitEnumCaseFromBacking($classId, $key);
        }

        return $this->jitConstantFromEntry($this->classConstants[$classId][$key]);
    }

    public function classConstFetchDynamic(
        int $classId,
        Variable $nameVar,
        Operand $classOp,
        ?Block $block = null,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        return ClassConstFetchHelper::fetchDynamic($this, $classId, $nameVar, $classOp, $block, $jit);
    }

    public function emitDirectTraitConstAccessErrorIfNeeded(int $classId, string $constName, ?Block $block = null): void
    {
        if ('class' === strtolower($constName)) {
            return;
        }
        $classLabel = $this->classNameForId($classId);
        if (!$this->isTraitClass(strtolower(ltrim($classLabel, '\\')))) {
            return;
        }
        if ($this->isInTraitMethodScopeForTraitId($classId, $block)) {
            return;
        }
        ErrorRaise::ensureLinked($this->context);
        ErrorRaise::emitRaise(
            $this->context,
            "Cannot access trait constant {$classLabel}::{$constName} directly"
        );
    }

    /** self::CONST inside trait methods lowers to T::CONST — allow in-trait scope (#9187, Zend/zend_traits.c). */
    public function isInTraitMethodScopeForTraitId(int $traitId, ?Block $block): bool
    {
        $traitLc = strtolower(ltrim($this->classNameForId($traitId), '\\'));
        if (null !== $block?->func?->class) {
            $funcClassLc = strtolower(ltrim($block->func->class->value, '\\'));
            if ($funcClassLc === $traitLc) {
                return true;
            }
        }
        $declaringLc = null;
        if (null !== $block?->func?->class) {
            $declaringLc = strtolower(ltrim($block->func->class->value, '\\'));
        } elseif ('' !== $this->context->scope->className) {
            $declaringLc = strtolower(ltrim($this->context->scope->className, '\\'));
        }
        if (null === $declaringLc || !$this->hasDeclaredClass($declaringLc)) {
            return false;
        }
        $methodLc = strtolower((string) ($block?->func?->name ?? ''));
        if ('' === $methodLc) {
            return false;
        }
        $sourceTraitLc = $this->traitMethodSource($this->lookup($declaringLc), $methodLc);

        return null !== $sourceTraitLc && $sourceTraitLc === $traitLc;
    }

    /**
     * @return list<array{0: string, 1: array{type: int, value: int|float|bool|string|null}}>
     */
    public function classConstantsForId(int $classId): array
    {
        $out = [];
        foreach ($this->classConstants[$classId] ?? [] as $key => $entry) {
            $out[] = [$key, $entry];
        }

        return $out;
    }

    public function classConstDisplayName(int $classId, string $constKey): string
    {
        $key = strtolower($constKey);

        return $this->classConstDisplayNames[$classId][$key] ?? $constKey;
    }

    public function defineStaticProperty(
        int $classId,
        string $name,
        int $jitType,
        ?VMVariable $default = null,
        ?VMVariable $prototype = null,
        bool $forceTypedWithoutDefault = false,
        int $visibilityFlags = \PHPCfg\Func::FLAG_PUBLIC
    ): void {
        $key = strtolower($name);
        if (isset($this->staticPropertyGlobals[$classId][$key])) {
            return;
        }
        $typedWithoutDefault = $forceTypedWithoutDefault
            || (
                null === $default
                && null !== $prototype
                && $prototype->hasDeclaredTypeConstraint()
                && $prototype->isUndefined()
            );
        if (
            Variable::TYPE_NATIVE_LONG !== $jitType
            && Variable::TYPE_STRING !== $jitType
            && Variable::TYPE_NATIVE_BOOL !== $jitType
            && Variable::TYPE_NATIVE_DOUBLE !== $jitType
            && Variable::TYPE_VALUE !== $jitType
            && Variable::TYPE_HASHTABLE !== $jitType
        ) {
            throw new \LogicException(
                'JIT static property requires a scalar declared type (int, string, float, bool), hashtable, or boxed value'
            );
        }
        $globalName = 'sp_'.$classId.'_'.$key;
        if (Variable::TYPE_VALUE === $jitType) {
            $llvmType = $this->context->getTypeFromString('__value__*');
            $global = $this->context->module->addGlobal($llvmType, $globalName);
            $global->setInitializer($llvmType->constNull());
        } elseif (Variable::TYPE_HASHTABLE === $jitType) {
            $llvmType = $this->context->getTypeFromString('__hashtable__*');
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
        $entry = [
            'type' => $jitType,
            'global' => $global,
            'default' => $default,
            'typedWithoutDefault' => $typedWithoutDefault,
            'initGlobal' => null,
        ];
        if ($typedWithoutDefault) {
            $initGlobalName = 'sp_init_'.$classId.'_'.$key;
            $initType = $this->context->getTypeFromString('int1');
            $initGlobal = $this->context->module->addGlobal($initType, $initGlobalName);
            $initGlobal->setInitializer($initType->constInt(0, false));
            $entry['initGlobal'] = $initGlobal;
        }
        $this->staticPropertyGlobals[$classId][$key] = $entry;
        $this->staticPropertyVisibility[$classId][$key] = MethodVisibility::mask($visibilityFlags);
        $this->staticPropertyDeclaringClassId[$classId][$key] = $classId;
        if (Variable::TYPE_STRING === $jitType && null !== $default) {
            $this->initStaticStringPropertyDefault($global, $default);
        }
        if (Variable::TYPE_HASHTABLE === $jitType) {
            if (null === $default || VMVariable::TYPE_ARRAY === $default->type) {
                if (!$this->deferStaticHashtableInitInAot($classId)) {
                    $this->initStaticHashtablePropertyEmpty($global);
                }
            } else {
                throw new \LogicException(
                    'Static array property default must be an empty array literal for '.$this->classNameForId($classId).'::'.$name
                );
            }
        }
        if (Variable::TYPE_VALUE === $jitType && null !== $default && EnumCaseSupport::isEnumCaseVariable($default)) {
            $this->initStaticValuePropertyEnumCase($global, $default);
        } elseif (Variable::TYPE_VALUE === $jitType && null !== $default && VMVariable::TYPE_ARRAY === $default->type) {
            $this->initStaticValuePropertyEmptyArray($global);
        } elseif (Variable::TYPE_VALUE === $jitType && null !== $default && VMVariable::TYPE_NULL !== $default->type) {
            $this->initStaticValuePropertyScalarDefault($global, $default);
        } elseif (Variable::TYPE_VALUE === $jitType && (null === $default || VMVariable::TYPE_NULL === $default->type)) {
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
        $this->context->positionBuilderAtInitEmission();
        $str = $this->context->builder->load(
            $this->context->constantStringFromString($value->toString())
        );
        $owned = $this->context->builder->call(
            $this->context->lookupFunction('__string__separate'),
            $str
        );
        $this->context->builder->store($owned, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($this->context, $restore);
        }
    }

    /** Allocate a null {@see __value__} box for untyped static properties (bootstrap JIT helpers). */
    private function initStaticValuePropertyNull(\PHPLLVM\Value $global): void
    {
        $restore = $this->context->builder->getInsertBlock();
        $this->context->positionBuilderAtInitEmission();
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
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($this->context, $restore);
        }
    }

    /** Initialize a typed static array property with an empty hashtable (#8716). */
    private function initStaticHashtablePropertyEmpty(\PHPLLVM\Value $global): void
    {
        $restore = $this->context->builder->getInsertBlock();
        $this->context->positionBuilderAtInitEmission();
        $ht = HashTableHelper::alloc($this->context);
        $this->context->builder->store($ht, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($this->context, $restore);
        }
    }

    /**
     * WeakRef registry slot tables allocate on first write — eager __init__ hashtable alloc
     * runs before runtime tables are ready and segfaults HelloWorld AOT (#11437).
     */
    private function deferStaticHashtableInitInAot(int $classId): bool
    {
        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE !== $this->context->loadType) {
            return false;
        }
        $classLc = strtolower(ltrim($this->classNameForId($classId), '\\'));

        return 'phpcompiler\\ext\\standard\\weakrefregistryjithelper' === $classLc;
    }

    /** Box an empty compile-time array default into a union/DNF static {@see __value__} property (#8708, #8719, DomRegistry::$states #6140). */
    private function initStaticValuePropertyEmptyArray(\PHPLLVM\Value $global): void
    {
        $restore = $this->context->builder->getInsertBlock();
        $this->context->positionBuilderAtInitEmission();
        $valueType = $this->context->getTypeFromString('__value__');
        $heapVal = $this->context->memory->malloc($valueType);
        $heapPtr = $this->context->builder->pointerCast(
            $heapVal,
            $this->context->getTypeFromString('__value__*')
        );
        $ht = HashTableHelper::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeHashtable'),
            $heapPtr,
            $ht
        );
        $this->context->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($this->context, $restore);
        }
    }

    /** Box a compile-time scalar default into a union/DNF static {@see __value__} property (#8726). */
    private function initStaticValuePropertyScalarDefault(\PHPLLVM\Value $global, VMVariable $default): void
    {
        $restore = $this->context->builder->getInsertBlock();
        $this->context->positionBuilderAtInitEmission();
        $valueType = $this->context->getTypeFromString('__value__');
        $heapVal = $this->context->memory->malloc($valueType);
        $heapPtr = $this->context->builder->pointerCast(
            $heapVal,
            $this->context->getTypeFromString('__value__*')
        );
        $valueMap = $this->context->structFieldMap['__value__'];
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt($default->type, false),
            $this->context->builder->structGep($heapVal, $valueMap['type'])
        );
        if (VMVariable::TYPE_STRING === $default->type) {
            $str = $this->context->builder->load(
                $this->context->constantStringFromString($default->toString())
            );
            $owned = $this->context->builder->call(
                $this->context->lookupFunction('__string__separate'),
                $str
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                $heapPtr,
                $owned
            );
        } elseif (VMVariable::TYPE_INTEGER === $default->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $heapPtr,
                $this->context->getTypeFromString('int64')->constInt($default->toInt(), false)
            );
        } elseif (VMVariable::TYPE_FLOAT === $default->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeDouble'),
                $heapPtr,
                $this->context->getTypeFromString('double')->constReal($default->toFloat())
            );
        } elseif (VMVariable::TYPE_BOOLEAN === $default->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $heapPtr,
                $this->context->getTypeFromString('int64')->constInt($default->toBool() ? 1 : 0, false)
            );
        } else {
            throw new \LogicException(
                'Static union/DNF property default must be a scalar compile-time constant'
            );
        }
        $this->context->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($this->context, $restore);
        }
    }

    /** Box a compile-time enum case singleton into a typed static {@see __value__} property (#5891). */
    private function initStaticValuePropertyEnumCase(\PHPLLVM\Value $global, VMVariable $default): void
    {
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($default);
        if (null === $enumClass) {
            throw new \LogicException('Static enum case property default requires enum class');
        }
        $enumClassId = $this->lookup(strtolower($enumClass->name));
        $caseKey = strtolower(EnumCaseSupport::enumCaseNameForVariable($default));
        $globalName = $this->ensureEnumCaseSingletonGlobal($enumClassId, $caseKey);
        $this->context->emitInInit(function (Context $ctx) use ($global, $globalName): void {
            $objGlobal = $ctx->module->getNamedGlobal($globalName);
            if (null === $objGlobal) {
                throw new \LogicException("Missing enum case singleton global: {$globalName}");
            }
            $valueType = $ctx->getTypeFromString('__value__');
            $heapVal = $ctx->memory->malloc($valueType);
            $heapPtr = $ctx->builder->pointerCast(
                $heapVal,
                $ctx->getTypeFromString('__value__*')
            );
            $obj = $ctx->builder->load($objGlobal);
            $ctx->builder->call(
                $ctx->lookupFunction('__value__writeObject'),
                $heapPtr,
                $obj
            );
            $ctx->builder->store($heapPtr, $global);
        });
    }

    public function staticPropertyUnset(int $classId, string $name): void
    {
        $key = strtolower($name);
        if (!isset($this->staticPropertyGlobals[$classId][$key])) {
            throw new \LogicException("Undefined static property: {$name}");
        }
        $entry = $this->staticPropertyGlobals[$classId][$key];
        $global = $entry['global'];
        if (null !== ($entry['initGlobal'] ?? null)) {
            $this->context->builder->store(
                $this->context->getTypeFromString('int1')->constInt(0, false),
                $entry['initGlobal']
            );
        }
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

    /**
     * @return array{
     *     visibility: int,
     *     setVisibility: int,
     *     getVisibility: int,
     *     declaringClassId: int,
     *     declaringClassName: string
     * }|null
     */
    public function staticPropertyVisibilityMeta(int $classId, string $name): ?array
    {
        $key = strtolower($name);
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            if (!isset($this->staticPropertyGlobals[$currentId][$key])) {
                $parentLc = $this->parentClassLc($this->classNameForId($currentId));
                if (null === $parentLc) {
                    break;
                }
                $currentId = $this->lookup($parentLc);
                continue;
            }
            $declId = $this->staticPropertyDeclaringClassId[$currentId][$key] ?? $currentId;

            return [
                'visibility' => $this->staticPropertyVisibility[$currentId][$key] ?? \PHPCfg\Func::FLAG_PUBLIC,
                'setVisibility' => $this->staticPropertySetVisibility[$currentId][$key] ?? 0,
                'getVisibility' => $this->staticPropertyGetVisibility[$currentId][$key] ?? 0,
                'declaringClassId' => $declId,
                'declaringClassName' => $this->classNameForId($declId),
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     visibility: int,
     *     setVisibility: int,
     *     getVisibility: int,
     *     declaringClassId: int,
     *     declaringClassName: string
     * }|null
     */
    public function instancePropertyVisibilityMeta(int $classId, string $name): ?array
    {
        $key = strtolower($name);
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            if (!isset($this->properties[$currentId])) {
                $parentLc = $this->parentClassLc($this->classNameForId($currentId));
                if (null === $parentLc) {
                    break;
                }
                $currentId = $this->lookup($parentLc);
                continue;
            }
            $found = false;
            foreach ($this->properties[$currentId] as $propset) {
                if (strtolower($propset[1]) === $key) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $parentLc = $this->parentClassLc($this->classNameForId($currentId));
                if (null === $parentLc) {
                    break;
                }
                $currentId = $this->lookup($parentLc);
                continue;
            }

            return [
                'visibility' => $this->propertyVisibility($currentId, $name),
                'setVisibility' => $this->propertySetVisibility($currentId, $name),
                'getVisibility' => $this->propertyGetVisibility($currentId, $name),
                'declaringClassId' => $currentId,
                'declaringClassName' => $this->classNameForId($currentId),
            ];
        }

        return null;
    }

    public function staticPropertyFetch(int $classId, string $name): Variable
    {
        $key = strtolower($name);
        if (!isset($this->staticPropertyGlobals[$classId][$key])) {
            throw new \LogicException("Undefined static property: {$name}");
        }
        $entry = $this->staticPropertyGlobals[$classId][$key];
        if (!empty($entry['typedWithoutDefault']) && null !== ($entry['initGlobal'] ?? null)) {
            TypedPropertyUninitGuard::emitBeforeStaticRead(
                $this->context,
                $entry['initGlobal'],
                $this->classNameForId($classId),
                $name
            );
        }
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
            $var->staticPropertyInitGlobal = $entry['initGlobal'] ?? null;
            $var->staticPropertyDnfArms = $this->dnfArmsForStaticProperty($classId, $name);

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
        $var->staticPropertyInitGlobal = $entry['initGlobal'] ?? null;
        $var->staticPropertyDnfArms = $this->dnfArmsForStaticProperty($classId, $name);

        return $var;
    }

    /**
     * Runtime static property name (`Class::$$name`, issue #4597).
     */
    public function staticPropertyFetchDynamic(int $classId, Variable $nameVar): Variable
    {
        $globals = $this->staticPropertyGlobals[$classId] ?? [];
        if ([] === $globals) {
            throw new \LogicException('Dynamic static property fetch requires at least one declared static property');
        }

        if (1 === count($globals)) {
            $propName = array_key_first($globals);
            $runtimeName = JitStringArg::lowerDominating($this->context, $nameVar, 'dynamic static property name');
            $litLoaded = $this->context->builder->load($this->context->constantStringFromString($propName));
            $match = JitStringCompare::identical($this->context, $runtimeName, $litLoaded);
            $fn = BasicBlockHelper::parentFunction($this->context);
            $entry = $this->context->builder->getInsertBlock();
            $ok = $fn->appendBasicBlock('dyn_static_prop_one_ok');
            $fail = $fn->appendBasicBlock('dyn_static_prop_one_fail');
            $this->context->builder->branchIf($match, $ok, $fail);
            $this->context->builder->positionAtEnd($fail);
            $classLabel = $this->classNameForId($classId);
            ErrorRaise::ensureLinked($this->context);
            ErrorRaise::emitRaise(
                $this->context,
                'Access to undeclared static property '.$classLabel.'::$'
            );
            $this->context->builder->returnVoid();
            $this->context->builder->positionAtEnd($ok);

            return $this->staticPropertyFetch($classId, $propName);
        }

        $runtimeName = JitStringArg::lowerDominating($this->context, $nameVar, 'dynamic static property name');
        $fn = BasicBlockHelper::parentFunction($this->context);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('dyn_static_prop_done');
        $exit = $fn->appendBasicBlock('dyn_static_prop_exit');
        $fallback = $fn->appendBasicBlock('dyn_static_prop_undef');
        $destSlot = JitValueBox::alloc($this->context);
        $multiGlobal = count($globals) > 1;
        $globalSlot = null;
        if ($multiGlobal) {
            $firstGlobal = reset($globals)['global'];
            $globalSlot = $this->context->memory->malloc($firstGlobal->getType());
        }
        $checkBlock = $entry;
        $i = 0;
        foreach ($globals as $propName => $entry) {
            $this->context->builder->positionAtEnd($checkBlock);
            $litLoaded = $this->context->builder->load($this->context->constantStringFromString($propName));
            $match = JitStringCompare::identical($this->context, $runtimeName, $litLoaded);
            $caseBlock = $fn->appendBasicBlock('dyn_static_prop_case_'.$classId.'_'.$i);
            $nextCheck = $i + 1 < count($globals)
                ? $fn->appendBasicBlock('dyn_static_prop_try_'.$classId.'_'.($i + 1))
                : $fallback;
            $this->context->builder->branchIf($match, $caseBlock, $nextCheck);
            $this->context->builder->positionAtEnd($caseBlock);
            $fetched = $this->staticPropertyFetch($classId, $propName);
            $this->boxStaticFetchedIntoValue($destSlot, $fetched, $entry['type']);
            if ($multiGlobal && null !== $globalSlot) {
                $this->context->builder->store($entry['global'], $globalSlot);
            }
            $this->context->builder->branch($done);
            $checkBlock = $nextCheck;
            ++$i;
        }
        $this->context->builder->positionAtEnd($fallback);
        $classLabel = $this->classNameForId($classId);
        ErrorRaise::ensureLinked($this->context);
        ErrorRaise::emitRaise(
            $this->context,
            'Access to undeclared static property '.$classLabel.'::$'
        );
        $this->context->builder->returnVoid();
        $this->context->builder->positionAtEnd($done);
        $this->context->builder->branch($exit);
        $this->context->builder->positionAtEnd($exit);
        $result = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $destSlot
        );
        if (1 === count($globals)) {
            $onlyEntry = reset($globals);
            $result->staticPropertyGlobal = $onlyEntry['global'];
            $result->staticPropertyType = $onlyEntry['type'];
        } elseif (null !== $globalSlot) {
            $result->staticPropertyGlobal = $this->context->builder->load($globalSlot);
            $types = array_unique(array_map(
                static fn (array $entry): int => $entry['type'],
                $globals
            ));
            if (1 !== count($types)) {
                throw new \LogicException(
                    'Dynamic static property assign JIT requires uniform static property types per class'
                );
            }
            $result->staticPropertyType = $types[0];
        }

        return $result;
    }

    private function boxStaticFetchedIntoValue(
        PHPLLVM\Value $destSlot,
        Variable $fetched,
        int $propertyType
    ): void {
        $destPtr = JitValueBox::pointer($this->context, $destSlot);
        if (Variable::TYPE_VALUE === $propertyType) {
            JitValueBox::copyFromPointer(
                $this->context,
                $destSlot,
                JitValueBox::pointer($this->context, $fetched->value)
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
                $fetched->value
            );

            return;
        }

        throw new \LogicException(
            'Dynamic static property fetch JIT box unsupported type: '.Variable::getStringType($propertyType)
        );
    }

    /**
     * Runtime static property name for unset (`unset(Class::$$name)`, issue #4597).
     */
    public function staticPropertyUnsetDynamic(int $classId, Variable $nameVar): void
    {
        $globals = $this->staticPropertyGlobals[$classId] ?? [];
        if ([] === $globals) {
            throw new \LogicException('Dynamic static property unset requires at least one declared static property');
        }

        $runtimeName = JitStringArg::lowerDominating($this->context, $nameVar, 'dynamic static property name');
        $fn = BasicBlockHelper::parentFunction($this->context);
        $entry = $this->context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('dyn_static_prop_unset_done');
        $fallback = $fn->appendBasicBlock('dyn_static_prop_unset_undef');
        $checkBlock = $entry;
        $i = 0;
        foreach ($globals as $propName => $_entry) {
            $this->context->builder->positionAtEnd($checkBlock);
            $litLoaded = $this->context->builder->load($this->context->constantStringFromString($propName));
            $match = JitStringCompare::identical($this->context, $runtimeName, $litLoaded);
            $caseBlock = $fn->appendBasicBlock('dyn_static_prop_unset_case_'.$classId.'_'.$i);
            $nextCheck = $i + 1 < count($globals)
                ? $fn->appendBasicBlock('dyn_static_prop_unset_try_'.$classId.'_'.($i + 1))
                : $fallback;
            $this->context->builder->branchIf($match, $caseBlock, $nextCheck);
            $this->context->builder->positionAtEnd($caseBlock);
            $this->staticPropertyUnset($classId, $propName);
            $this->context->builder->branch($done);
            $checkBlock = $nextCheck;
            ++$i;
        }
        $this->context->builder->positionAtEnd($fallback);
        $classLabel = $this->classNameForId($classId);
        ErrorRaise::ensureLinked($this->context);
        ErrorRaise::emitRaise(
            $this->context,
            'Access to undeclared static property '.$classLabel.'::$'
        );
        $this->context->builder->returnVoid();
        $this->context->builder->positionAtEnd($done);
    }

    public function staticPropertyStore(
        \PHPLLVM\Value $global,
        Variable $value,
        int $propertyType,
        ?\PHPLLVM\Value $initGlobal = null
    ): void {
        if (Variable::TYPE_VALUE === $propertyType) {
            $this->staticPropertyStoreValueBox($global, $value);
            $this->markStaticPropertyInitialized($initGlobal);

            return;
        }
        if (Variable::TYPE_STRING === $propertyType) {
            if (Variable::TYPE_VALUE === $value->type) {
                $stored = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readString'),
                    JitValueBox::valuePtrFromVariable($this->context, $value)
                );
            } else {
                $stored = $this->context->helper->loadValue($value);
            }
            $this->context->builder->store($stored, $global);
            if (Variable::TYPE_STRING === $value->type) {
                $value->addref();
            }
            $this->markStaticPropertyInitialized($initGlobal);

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
            $this->markStaticPropertyInitialized($initGlobal);

            return;
        }
        $this->context->builder->store($this->context->helper->loadValue($value), $global);
        $this->markStaticPropertyInitialized($initGlobal);
    }

    private function markStaticPropertyInitialized(?\PHPLLVM\Value $initGlobal): void
    {
        if (null === $initGlobal) {
            return;
        }
        $this->context->builder->store(
            $this->context->getTypeFromString('int1')->constInt(1, false),
            $initGlobal
        );
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

    /**
     * is_subclass_of() object operand — strict subclass, excludes same class (#4358).
     */
    public function emitSubclassOf(Variable $expr, string $className): Variable
    {
        $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
        $objMap = $this->context->structFieldMap['__object__'];

        if (Variable::TYPE_OBJECT === $expr->type) {
            $obj = $this->context->helper->loadValue($expr);
            $classId = $this->context->builder->load(
                $this->context->builder->structGep($obj, $objMap['class_id'])
            );
            $match = $this->emitClassIdSubclassOf($classId, $className);

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
            $matches = $this->emitClassIdSubclassOf($classId, $className);
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

    private function emitClassIdSubclassOf(PHPLLVM\Value $classId, string $className): PHPLLVM\Value
    {
        $isInstance = $this->emitClassIdInstanceOf($classId, $className);
        $parentId = $this->lookup($className);
        $isSameClass = $this->context->builder->icmp(
            PHPLLVM\Builder::INT_EQ,
            $classId,
            $this->context->constantFromInteger($parentId, 'int64')
        );

        return $this->context->builder->and($isInstance, $this->context->builder->not($isSameClass));
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
     * @param list<string> $classNames
     */
    public function emitInstanceOfUnion(Variable $expr, array $classNames): Variable
    {
        $i1 = $this->context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($classNames as $name) {
            if ('' === $name) {
                continue;
            }
            $check = $this->emitInstanceOf($expr, $name);
            $bool = Variable::TYPE_NATIVE_BOOL === $check->type
                ? $check->value
                : $this->context->helper->loadValue($check);
            $acc = $this->context->builder->or($acc, $bool);
        }

        return new Variable(
            $this->context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $acc
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
            case Variable::TYPE_HASHTABLE:
                if (isset($entry['global'])) {
                    return $this->jitClassConstHashtableFromGlobal($entry);
                }
                if (!isset($entry['vmTable']) || !$entry['vmTable'] instanceof \PHPCompiler\VM\HashTable) {
                    throw new \LogicException('Missing VM table for class constant array');
                }

                return HashTableHelper::variableFromVmHashTable($this->context, $entry['vmTable']);
            case Variable::TYPE_OBJECT:
                return $this->jitClassConstObjectFromGlobal($entry);
            default:
                throw new \LogicException('Unsupported class constant type for JIT');
        }
    }

    /**
     * @param array{type: int, global: string} $entry
     */
    private function jitClassConstHashtableFromGlobal(array $entry): Variable
    {
        $globalName = $entry['global'];
        if (!isset($this->classConstHashtableGlobals[$globalName])) {
            throw new \LogicException("Missing class constant hashtable global: {$globalName}");
        }
        $ht = $this->context->builder->load($this->classConstHashtableGlobals[$globalName]);

        return new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    /**
     * @param array{type: int, global: string} $entry
     */
    private function jitClassConstObjectFromGlobal(array $entry): Variable
    {
        $globalName = $entry['global'];
        if (!isset($this->classConstObjectGlobals[$globalName])) {
            throw new \LogicException("Missing class constant object global: {$globalName}");
        }
        $global = $this->classConstObjectGlobals[$globalName];
        // Load the module-global immortal object directly (#3196, #4028). Boxing via
        // __value__writeObject runs valueDelref on the prior slot tag (TYPE_OBJECT) and
        // MCJIT execute can fault on immortal headers; native __object__* matches AOT.
        $obj = $this->context->builder->load($global);

        return new Variable(
            $this->context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
    }

    /**
     * @param array{type: int, value?: int|float|bool|string|null, global?: string} $left
     * @param array{type: int, value?: int|float|bool|string|null, global?: string} $right
     */
    private function classConstEntriesIdentical(array $left, array $right): bool
    {
        if (($left['type'] ?? null) !== ($right['type'] ?? null)) {
            return false;
        }
        if (isset($left['global']) || isset($right['global'])) {
            return ($left['global'] ?? null) === ($right['global'] ?? null);
        }

        return ($left['value'] ?? null) === ($right['value'] ?? null);
    }

    /**
     * Class body constant after trait use must not redefine an inherited trait constant
     * with an incompatible value (Zend/zend_traits.c, #7012).
     */
    private function rejectIncompatibleTraitClassConstOverride(
        int $classId,
        string $key,
        string $constDisplay,
        array $newEntry
    ): void {
        if (!isset($this->traitConstSources[$classId][$key], $this->classConstants[$classId][$key])) {
            return;
        }
        if ($this->classConstEntriesIdentical($this->classConstants[$classId][$key], $newEntry)) {
            return;
        }
        $className = $this->classNameForId($classId);
        $traitName = $this->traitConstSources[$classId][$key];
        throw new \LogicException(sprintf(
            '%s and %s define the same constant (%s) in the composition of %s. '
            .'However, the definition differs and is considered incompatible. Class was composed',
            $className,
            $traitName,
            $constDisplay,
            $className
        ));
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

    /** get_object_vars() on enum case singletons (#4809). */
    public function fetchEnumCaseBuiltinProperty(PHPLLVM\Value $obj, int $classId, string $nameLc): Variable
    {
        return ObjectEnumCasePropertyLlvm::enumCasePropertyFetch($this, $obj, $classId, $nameLc);
    }

    /**
     * Enum case __value__ entries used as array keys must throw Error (ext/standard/array.c #5538).
     */
    public function emitEnumCaseValueEntryStringCastError(Context $context, PHPLLVM\Value $valueEntry): void
    {
        ObjectEnumStringCastLlvm::emitEnumCaseValueEntryStringCastError($this, $context, $valueEntry);
    }

    /**
     * Non-enum object __value__ entries used as array keys must throw Error (ext/standard/array.c #4161).
     */
    public function emitObjectValueEntryStringCastError(Context $context, PHPLLVM\Value $valueEntry): void
    {
        ObjectEnumStringCastLlvm::emitObjectValueEntryStringCastError($this, $context, $valueEntry);
    }

    /**
     * Zend string cast on enum case objects must throw Error (zend_enum.c, #4819).
     */
    public function emitEnumObjectStringErrorIfMatches(Context $context, PHPLLVM\Value $objPtr): void
    {
        ObjectEnumStringCastLlvm::emitEnumObjectStringErrorIfMatches($this, $context, $objPtr);
    }

    /**
     * exit()/die() status: ExitStatus enum → backing int; other enums → Error; else TypeError (#7214, #7294).
     */
    public function exitStatusEnumClassId(): ?int
    {
        return $this->classes['exitstatus'] ?? null;
    }

    public function memoryUsageEnumClassId(): ?int
    {
        return $this->classes['memoryusage'] ?? null;
    }

    public function clockInterfaceEnumClassId(): ?int
    {
        return $this->classes['clockinterface'] ?? null;
    }

    public function phpInputFilterEnumClassId(): ?int
    {
        return $this->classes['phpinputfilter'] ?? null;
    }

    public function roundingModeEnumClassId(): ?int
    {
        return $this->classes['roundingmode'] ?? null;
    }

    public function infoViewEnumClassId(): ?int
    {
        return $this->classes['infoview'] ?? null;
    }

    public function connectionStatusEnumClassId(): ?int
    {
        return $this->classes['connectionstatus'] ?? null;
    }

    public function responseCodeEnumClassId(): ?int
    {
        return $this->classes['responsecode'] ?? null;
    }

    public function emitExitStatusFromEnumCaseObject(Context $context, PHPLLVM\Value $objPtr): void
    {
        ObjectExitStatusLlvm::emitExitStatusFromEnumCaseObject($this, $context, $objPtr);
    }

    public function emitExitStatusObjectGuard(Context $context, PHPLLVM\Value $objPtr): void
    {
        ObjectExitStatusLlvm::emitExitStatusObjectGuard($this, $context, $objPtr);
    }

    public function propertyFetch(PHPLLVM\Value $obj, string $class, string $name): Variable
    {
        $classId = $this->lookup('' !== $class ? $class : 'stdclass');
        $nameLc = strtolower($name);
        if ($this->isEnumClassId($classId) && EnumCasePropertyJitHelper::isBuiltinPropertyName($nameLc)) {
            return ObjectEnumCasePropertyLlvm::enumCasePropertyFetch($this, $obj, $classId, $nameLc);
        }
        if (EnumCasePropertyJitHelper::isBuiltinPropertyName($nameLc) && [] !== ($enumIds = $this->registeredEnumClassIds())) {
            return ObjectEnumCasePropertyLlvm::propertyFetchEnumCaseRuntimeDispatch($this, $obj, $nameLc, $enumIds);
        }

        return $this->propertyFetchOrdinary($obj, $class, $name, $classId);
    }

    /**
     * @return list<int>
     */
    public function registeredEnumClassIds(): array
    {
        $ids = [];
        foreach ($this->classIdToName as $id => $name) {
            if ($this->isEnumClassId((int) $id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function propertyFetchOrdinary(
        PHPLLVM\Value $obj,
        string $class,
        string $name,
        int $classId
    ): Variable {
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
                    $var->objectPropertyDnfArms = $this->dnfArmsForProperty($classId, $propset[1]);
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
                    $var->objectPropertyDnfArms = $this->dnfArmsForProperty($classId, $propset[1]);
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
                $var->objectPropertyDnfArms = $this->dnfArmsForProperty($classId, $propset[1]);
                $this->slotReceivers[spl_object_id($slot)] = $obj;

                return $var;
            }
        }
        throw new \LogicException("Could not find property $name for class $classId");
    }

    /**
     * isset($obj->prop) for a literal property name (issue #3603, #4586).
     */
    public function propertyIsSet(PHPLLVM\Value $obj, string $class, string $name): PHPLLVM\Value
    {
        $classId = $this->lookup('' !== $class ? $class : 'stdclass');
        $i1 = $this->context->getTypeFromString('int1');
        $hookIsset = PropertyHookDispatch::tryEmitPropertyIsSet(
            $this->context,
            $obj,
            $class,
            $name,
            $this->context->jitCurrentBlock
        );
        if (null !== $hookIsset) {
            return $hookIsset;
        }
        if (PropertyHookDispatch::emitWriteOnlyVirtualReadGuard(
            $this->context,
            null,
            $class,
            $name
        )) {
            return $i1->constInt(0, false);
        }
        if (!$this->hasProperty($classId, $name)) {
            return $i1->constInt(0, false);
        }
        $prop = $this->propertyFetch($obj, $class, $name);
        if (Variable::TYPE_VALUE === $prop->type) {
            $valueMap = $this->context->structFieldMap['__value__'];
            $typeByte = $this->context->builder->load(
                $this->context->builder->structGep($prop->value, $valueMap['type'])
            );
            $i8 = $this->context->getTypeFromString('int8');
            $nullType = $i8->constInt(Variable::TYPE_NULL, false);
            $undefType = $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED, false);
            $notNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_NE, $typeByte, $nullType);
            $notUndef = $this->context->builder->icmp(PHPLLVM\Builder::INT_NE, $typeByte, $undefType);

            return $this->context->builder->and($notNull, $notUndef);
        }
        $loaded = $this->context->helper->loadValue($prop);
        $nullPtr = $this->context->getTypeFromString('void*')->constNull();

        return $this->context->builder->icmp(PHPLLVM\Builder::INT_NE, $loaded, $nullPtr);
    }

    /** Null-init every declared slot on a freshly allocated object (#7188 json_decode stdClass). */
    public function initializePropertySlotsNull(PHPLLVM\Value $obj, int $classId): void
    {
        $null = $this->context->getTypeFromString('void*')->constNull();
        foreach ($this->properties[$classId] as $propset) {
            $this->context->builder->store($null, $this->propertySlotPtr($obj, $propset[3]));
        }
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

        $runtimeName = JitStringArg::lowerPropertyName($this->context, $nameVar);

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
            TypedPropertyUninitGuard::emitBeforeRead($this->context, $fetched);
            $this->boxFetchedPropertyIntoValue($destSlot, $fetched, $propset[2]);
            $this->context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $this->context->builder->positionAtEnd($fallback);
        $magicRaw = MagicMethodDispatch::tryEmitMagicGetDynamic(
            $this->context,
            $obj,
            $class,
            $runtimeName,
            null
        );
        if (null !== $magicRaw) {
            $valuePtr = JitValueBox::coerceToValuePtrForStore($this->context, $magicRaw);
            JitValueBox::copyFromPointer($this->context, $destSlot, $valuePtr);
            $this->context->builder->branch($done);
        } else {
            $this->context->builder->call($this->context->lookupFunction('abort'));
        }
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

    public function boxFetchedPropertyIntoValueBox(PHPLLVM\Value $destSlot, Variable $fetched): void
    {
        $propertyType = $fetched->objectPropertyType ?? $fetched->type;
        $this->boxFetchedPropertyIntoValue($destSlot, $fetched, $propertyType);
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

        if (Variable::TYPE_STRING === $propertyType) {
            $stringPtr = null;
            if (Variable::TYPE_STRING === $value->type) {
                $str = $this->context->helper->loadValue($value);
                $stringPtr = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $str
                );
            } elseif (Variable::TYPE_VALUE === $value->type) {
                $valuePtr = Variable::KIND_VARIABLE === $value->kind
                    ? JitValueBox::pointer($this->context, $value->value)
                    : $value->value;
                $stringPtr = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readString'),
                    $valuePtr
                );
            }
            if (null !== $stringPtr) {
                $this->context->builder->store(
                    $this->context->builder->pointerCast($stringPtr, $voidPtr),
                    $slot
                );
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
            if (Variable::KIND_VALUE === $value->kind) {
                $valuePtr = JitValueBox::normalizeValuePtr(
                    $this->context,
                    $this->context->helper->loadValue($value)
                );
            } else {
                $valuePtr = JitValueBox::pointer($this->context, $value->value);
            }
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
