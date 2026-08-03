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
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\dom\DomConstants;
use PHPCompiler\ext\dom\VmDomLiving;
use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\ext\zip\ZipArchiveConstants;
use PHPCompiler\VM\ExceptionSupport;
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
use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\JIT\Builtin\Type;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\CloneWithReinitRuntime;
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
use PHPCompiler\VM\TraitPropertyCompatibility;
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
    /** @var array<int, array<string, bool>> class id => property lc => explicit read before set (#15995) */
    private array $propertyAsymmetricExplicitRead = [];

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

    /**
     * #[\Deprecated] on class constants — AOT/JIT fetch use-site (#27331).
     *
     * @var array<int, array<string, \PHPCompiler\Compiler\DeprecatedMetadata>>
     */
    private array $classConstDeprecated = [];

    /** @var array<int, array<string, string>> class id => const key lc => canonical display name */
    private array $classConstDisplayNames = [];

    /** @var array<int, array<string, string>> class id => const key lc => trait FQCN when imported via use Trait */
    private array $traitConstSources = [];

    /** @var array<string, PHPLLVM\Value> singleton __object__* globals for object class constants (#3196) */
    private array $classConstObjectGlobals = [];

    /** @var array<string, PHPLLVM\Value> immortal __hashtable__* globals for array class constants (#4900) */
    private array $classConstHashtableGlobals = [];

    /**
     * Per-class map: const key (lowercase) => value, used to shrink dynamic class const fetch lowering (#10200).
     *
     * @var array<int, \PHPLLVM\Value> class id => __hashtable__* LLVM global
     */
    private array $classConstMapGlobals = [];

    /** @var array<int, array<int, array{propertyType: int, type: int, value: int|float|bool|string|null}>> */
    private array $propertyDefaults = [];

    /** @var array<int, array<int, true>> class id => slot => typed init guard (#22021). */
    private array $typedPropertyInitGuardSlots = [];

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
    /** @var array<int, array<string, bool>> class id => static prop lc => explicit read before set (#15995) */
    private array $staticPropertyAsymmetricExplicitRead = [];
    /** @var array<int, array<string, int>> class id => static prop lc => declaring class id (#6785) */
    private array $staticPropertyDeclaringClassId = [];
    /** @var array<int, array<string, int>> class id => instance prop lc => declaring trait/class id (#7418) */
    private array $instancePropertyDeclaringClassId = [];
    /**
     * Trait FQCN origin for instance props imported via `use Trait` (#26593).
     * Declaring class id is the composing class; this map keeps conflict messages accurate.
     *
     * @var array<int, array<string, int>> class id => prop lc => trait class id
     */
    private array $instancePropertyTraitSourceId = [];
    /**
     * Trait instance property snapshot awaiting class-body merge check (#22850).
     *
     * @var array<int, array<string, array<string, mixed>>>
     */
    private array $pendingTraitInstancePropertyOverride = [];

    private ?int $splObjectStorageClassId = null;

    private ?int $weakReferenceClassId = null;
    /** @var array<int, true> class ids with clone_obj disabled (Exception/Error, WeakReference; #25870, #25962) */
    private array $denyCloneClassIds = [];

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

    /** @var array<int, true> ZEND_ACC_NO_DYNAMIC_PROPERTIES class ids (#26055, #26371) */
    private array $noDynamicPropertiesClassIds = [];

    /** @var array<int, array<string, true>> class id => property lc => true (#3149, #3432) */
    private array $readonlyPropertyNames = [];

    /**
     * Handler-style write reject (assign only) — not ZEND_ACC_READONLY (#26154 DatePeriod).
     *
     * @var array<int, array<string, true>> class id => property lc => true
     */
    private array $writeRejectPropertyNames = [];

    /** @var array<int, array<string, true>> class id => property lc => true (#22451, #22450) */
    private array $finalPropertyNames = [];

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
        ObjectDestructorLlvm::implementInvokeDestructor($this);
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
    public function classIdsWithDestructor(): array
    {
        $ids = [];
        foreach ($this->methodVisibility as $classId => $methods) {
            if (isset($methods['__destruct'])) {
                $ids[] = $classId;
            }
        }

        return $ids;
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
        // stdClass shares one layout across allocates; nested casts / dynamic writes call
        // defineProperty after earlier allocates — reserve headroom (#26818).
        $allocSlots = $propCount;
        if ($this->allowsDynamicProperties($classId)) {
            $allocSlots = max($propCount, 16);
        }
        if (0 === $allocSlots) {
            $obj = $this->context->memory->malloc($objType);
        } else {
            $obj = $this->context->memory->mallocWithExtra(
                $objType,
                $this->context->constantFromInteger(8 * $allocSlots, 'size_t')
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

        if ($allocSlots > 0) {
            $this->initPropertySlots($obj, $allocSlots);
        }
        if ($propCount > 0) {
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
        StringCaseCompare::ensureStrncasecmpLinked($context);
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

    public function allocateForRuntimeClassId(
        PHPLLVM\Value $classIdVal,
        ?\PHPCompiler\JIT $jit = null
    ): PHPLLVM\Value {
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
            $nonInstantiable = \PHPCompiler\JIT\InstantiableClassJitGuard::userInstantiationErrorMessage(
                $this,
                $id
            );
            if (null !== $nonInstantiable) {
                // Do not use emitBeforeAllocate here: outside try it returnVoid()s from the
                // enclosing function and poisons later TYPE_NEW / try regions (#27156).
                if ([] !== $this->context->tryCatch->handlerStack) {
                    \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
                        $this->context,
                        'Error',
                        $nonInstantiable,
                        $jit
                    );
                } else {
                    \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                    \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $nonInstantiable);
                    $this->context->builder->call($this->context->lookupFunction('abort'));
                }
                $checkBlock = $nextCheck;
                continue;
            }
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
        // Unknown runtime classname — catchable Error when inside try (#27156 / #4242).
        $notFound = 'Class not found';
        if ([] !== $this->context->tryCatch->handlerStack) {
            \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
                $this->context,
                'Error',
                $notFound,
                $jit
            );
        } else {
            \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
            \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $notFound);
            $this->context->builder->call($this->context->lookupFunction('abort'));
        }
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
            $var = $this->jitConstantFromEntry($this->propertyDefaultConstEntry($entry));
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

    public function propertySlotPtr(PHPLLVM\Value $obj, int $slotIndex): PHPLLVM\Value
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

    public function propNameIdFor(string $name): ?int
    {
        return $this->propNameMap[$name] ?? null;
    }

    public function propNameIdAfterDefine(string $name): int
    {
        return $this->propNameMap[$name];
    }

    /**
     * @return list<array{0: int, 1: string, 2: int, 3: int}>
     */
    public function propertySetsForClass(int $classId): array
    {
        return $this->properties[$classId] ?? [];
    }

    public function recordSlotReceiver(PHPLLVM\Value $slot, PHPLLVM\Value $obj): void
    {
        $this->slotReceivers[spl_object_id($slot)] = $obj;
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

    public function setClassNoDynamicProperties(int $classId, bool $rejects): void
    {
        if ($rejects) {
            $this->noDynamicPropertiesClassIds[$classId] = true;
        } else {
            unset($this->noDynamicPropertiesClassIds[$classId]);
        }
    }

    /** ZEND_ACC_NO_DYNAMIC_PROPERTIES — Error on undeclared write (#26371). */
    public function rejectsDynamicProperties(int $classId): bool
    {
        return isset($this->noDynamicPropertiesClassIds[$classId]);
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
        if (isset($this->finalPropertyNames[$parentId])) {
            foreach ($this->finalPropertyNames[$parentId] as $propLc => $_) {
                $this->finalPropertyNames[$childId][$propLc] = true;
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
     * Reject userland Assign only (unset + Reflection isReadOnly stay Zend-like) (#26154).
     */
    public function markPropertyWriteReject(int $classId, string $name): void
    {
        $this->writeRejectPropertyNames[$classId][strtolower($name)] = true;
    }

    public function isPropertyWriteReject(int $classId, string $name): bool
    {
        return isset($this->writeRejectPropertyNames[$classId][strtolower($name)]);
    }

    public function markPropertyFinal(int $classId, string $name): void
    {
        $this->finalPropertyNames[$classId][strtolower($name)] = true;
    }

    public function isPropertyFinal(int $classId, string $name): bool
    {
        return isset($this->finalPropertyNames[$classId][strtolower($name)]);
    }

    /**
     * @return list<string> lowercased property names marked final on $classId (#23845)
     */
    public function finalPropertyNamesForClassId(int $classId): array
    {
        if (!isset($this->finalPropertyNames[$classId])) {
            return [];
        }

        return array_keys($this->finalPropertyNames[$classId]);
    }

    /**
     * True when set visibility differs from read visibility (#3165, #23110).
     * Implicit-final private(set) properties use this path for writes, not the plain final ban.
     */
    public function propertyHasDistinctAsymmetricSetVisibility(int $classId, string $name): bool
    {
        $readVis = $this->propertyVisibility($classId, $name);
        $setVis = \PHPCompiler\PropertyVisibility::effectiveSetVisibility(
            $readVis,
            $this->propertySetVisibility($classId, $name)
        );

        return $setVis !== \PHPCompiler\MethodVisibility::mask($readVis);
    }

    /**
     * @return list<int> class ids declaring $name as a final instance property (#22451)
     */
    public function finalPropertyClassIdsForProperty(string $name): array
    {
        $lc = strtolower($name);
        $ids = [];
        foreach ($this->finalPropertyNames as $classId => $props) {
            if (isset($props[$lc])) {
                $ids[] = $classId;
            }
        }

        return $ids;
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

    /**
     * @return list<int> class ids with handler write-reject for $name (assign only; #26154)
     */
    public function writeRejectPropertyClassIdsForProperty(string $name): array
    {
        $lc = strtolower($name);
        $ids = [];
        foreach ($this->writeRejectPropertyNames as $classId => $props) {
            if (isset($props[$lc])) {
                $ids[] = $classId;
            }
        }

        return $ids;
    }

    public function hasReadonlyPropertyGuards(): bool
    {
        return [] !== $this->readonlyClassIds
            || [] !== $this->readonlyPropertyNames
            || [] !== $this->writeRejectPropertyNames
            || [] !== $this->finalPropertyNames;
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
        // Flattened Zend ce->interfaces: Iterator, Traversable (#25790).
        $this->setInterfaceExtends('SeekableIterator', ['Iterator', 'Traversable']);
        $this->markInterfaceClass('Reflector');
        // php-src php_dom.stub.php — classic ParentNode/ChildNode are independent (#22389).
        $this->markInterfaceClass('DOMParentNode');
        $this->markInterfaceClass('DOMChildNode');
        $this->setClassInterfaces('DOMDocument', ['domparentnode']);
        $this->setClassInterfaces('DOMDocumentFragment', ['domparentnode']);
        $this->setClassInterfaces('DOMElement', ['domparentnode', 'domchildnode']);
        $this->setClassInterfaces('DOMCharacterData', ['domchildnode']);
        $this->setClassInterfaces('DOMText', ['domchildnode']);
        $this->setClassInterfaces('DOMComment', ['domchildnode']);
        $this->setClassInterfaces('DOMCdataSection', ['domchildnode']);
        $this->ensureDomLivingParentChildInterfaces();
        // php-src session.stub.php — SessionId / UpdateTimestamp do not extend Handler (#22262).
        $this->markInterfaceClass('SessionHandlerInterface');
        $this->markInterfaceClass('SessionIdInterface');
        $this->markInterfaceClass('SessionUpdateTimestampHandlerInterface');
        $this->setClassInterfaces('SessionHandler', ['SessionHandlerInterface', 'SessionIdInterface']);
        $this->markInterfaceClass('Random\\Engine');
        $this->markInterfaceClass('Random\\CryptoSafeEngine');
        $this->setInterfaceExtends('Random\\CryptoSafeEngine', ['Random\\Engine']);
    }

    /**
     * Dom\ParentNode / Dom\ChildNode for instanceof / interface_exists under PROFILE=8.4 (#20961).
     *
     * Living ChildNode does not extend ParentNode — same as classic DOMChildNode (php_dom.stub.php; #22389).
     */
    private function ensureDomLivingParentChildInterfaces(): void
    {
        if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
            return;
        }
        $this->markInterfaceClass('Dom\\ParentNode');
        $this->markInterfaceClass('Dom\\ChildNode');
        $this->setClassInterfaces('Dom\\Document', [VmDomLiving::CLASS_PARENT_NODE]);
        $this->setClassInterfaces('Dom\\HTMLDocument', [VmDomLiving::CLASS_PARENT_NODE]);
        $this->setClassInterfaces('Dom\\XMLDocument', [VmDomLiving::CLASS_PARENT_NODE]);
        $this->setClassInterfaces('Dom\\DocumentFragment', [VmDomLiving::CLASS_PARENT_NODE]);
        $this->setClassInterfaces('Dom\\Element', [
            VmDomLiving::CLASS_PARENT_NODE,
            VmDomLiving::CLASS_CHILD_NODE,
        ]);
        $this->setClassInterfaces('Dom\\HTMLElement', [
            VmDomLiving::CLASS_PARENT_NODE,
            VmDomLiving::CLASS_CHILD_NODE,
        ]);
        $this->setClassInterfaces('Dom\\CharacterData', [VmDomLiving::CLASS_CHILD_NODE]);
        $this->setClassInterfaces('Dom\\Text', [VmDomLiving::CLASS_CHILD_NODE]);
        $this->setClassInterfaces('Dom\\Comment', [VmDomLiving::CLASS_CHILD_NODE]);
        $this->setClassInterfaces('Dom\\CDATASection', [VmDomLiving::CLASS_CHILD_NODE]);
        $this->setClassInterfaces('Dom\\ProcessingInstruction', [VmDomLiving::CLASS_CHILD_NODE]);
        $this->setClassInterfaces('Dom\\DocumentType', [VmDomLiving::CLASS_CHILD_NODE]);
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
        $classId = $this->declareClass($name);
        // ZEND_ACC_NO_DYNAMIC_PROPERTIES — Error on undeclared write (#26588, zend_enum.c).
        $this->setClassNoDynamicProperties($classId, true);
        $this->setClassAllowsDynamicProperties($classId, false);

        return $classId;
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
        $key = \PHPCompiler\ClassConstName::key($caseKey);
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
            // Re-assert seal in case declareClass paths cleared flags (#26588).
            $this->setClassNoDynamicProperties($classId, true);
            $this->setClassAllowsDynamicProperties($classId, false);
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
        $key = \PHPCompiler\ClassConstName::key($caseName);
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
     * php-src: zend_register_class_alias_ex — resolves alias-of-alias to canonical class (#11639).
     */
    public function registerClassAlias(string $original, string $alias): bool
    {
        $aliasLc = strtolower(ltrim($alias, '\\'));
        $originalLc = strtolower(ltrim($original, '\\'));

        if (!isset($this->classes[$originalLc])) {
            $vmContext = $this->context->runtime->vmContext ?? null;
            if (null !== $vmContext) {
                $vmContext->errors->triggerError(
                    \sprintf('Class "%s" not found', $original),
                    \PHPCompiler\VM\ErrorReporter::E_WARNING
                );
            }

            return false;
        }
        $canonicalOriginalLc = $originalLc;
        while (isset($this->classAliasToOriginalLc[$canonicalOriginalLc])) {
            $canonicalOriginalLc = $this->classAliasToOriginalLc[$canonicalOriginalLc];
        }

        $classId = $this->classes[$canonicalOriginalLc];
        if (isset($this->externalOnlyClassIds[$classId])) {
            throw new \ValueError(
                'class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given'
            );
        }

        if (isset($this->classes[$aliasLc]) || isset($this->classAliasToOriginalLc[$aliasLc])) {
            $vmContext = $this->context->runtime->vmContext ?? null;
            if (null !== $vmContext) {
                $vmContext->errors->triggerError(
                    \sprintf('Cannot declare class %s, because the name is already in use', $alias),
                    \PHPCompiler\VM\ErrorReporter::E_WARNING
                );
            }

            return false;
        }

        $this->classes[$aliasLc] = $classId;
        $this->classAliasToOriginalLc[$aliasLc] = $canonicalOriginalLc;
        if (isset($this->interfaceClassLcs[$canonicalOriginalLc])) {
            $this->interfaceClassLcs[$aliasLc] = true;
            if (isset($this->interfaceExtendsLc[$canonicalOriginalLc])) {
                $this->interfaceExtendsLc[$aliasLc] = $this->interfaceExtendsLc[$canonicalOriginalLc];
            }
            if (isset($this->classInterfacesLc[$canonicalOriginalLc])) {
                $this->classInterfacesLc[$aliasLc] = $this->classInterfacesLc[$canonicalOriginalLc];
            }
            unset($this->classAllInterfacesLc[$aliasLc]);
        }
        if (isset($this->traitClassLcs[$canonicalOriginalLc])) {
            $this->traitClassLcs[$aliasLc] = true;
        }
        if (isset($this->classUsedTraitNames[$canonicalOriginalLc])) {
            $this->classUsedTraitNames[$aliasLc] = $this->classUsedTraitNames[$canonicalOriginalLc];
        }
        if (isset($this->enums[$canonicalOriginalLc])) {
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

    public function classIdForLowerName(string $lc): ?int
    {
        return $this->classes[$lc] ?? null;
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
            $display = $resolved ?? $classLc;
            if (str_starts_with($display, 'PHPCompiler\\')) {
                continue;
            }
            $names[] = $display;
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
        // PHP 8.3+ readonly reinit window during __clone (zend_readonly.c; #23526).
        // Readonly-class props stay immutable — mirror CloneWithSupport::beginCloneMagicReinit.
        $reinitNames = [];
        if (CompilerVersion::supportsReadonlyCloneReinit()
            && !$this->isReadonlyClass($classId)) {
            foreach ($this->properties[$classId] ?? [] as $propset) {
                $propName = $propset[1];
                if ($this->isPropertyReadonly($classId, $propName)) {
                    $reinitNames[] = $propName;
                }
            }
        }
        if ([] !== $reinitNames) {
            CloneWithReinitRuntime::emitBegin($this->context, $cloned, $reinitNames);
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
        if ([] !== $reinitNames) {
            CloneWithReinitRuntime::emitEnd($this->context, $cloned);
        }
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
                $var = $this->jitConstantFromEntry($this->propertyDefaultConstEntry($entry));
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

    /** Mark clone_obj disabled for this class id (php-src handlers.clone_obj = NULL). */
    public function markDenyClone(int $classId): void
    {
        $this->denyCloneClassIds[$classId] = true;
    }

    public function classOrAncestorDeniesClone(int $classId): bool
    {
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            if (isset($this->denyCloneClassIds[$currentId])) {
                return true;
            }
            $parentLc = $this->parentClassLc($this->classNameForId($currentId));
            if (null === $parentLc || !isset($this->classes[$parentLc])) {
                return false;
            }
            $currentId = $this->classes[$parentLc];
        }

        return false;
    }

    /**
     * Registered class ids that must reject clone, with display names for the Error message (#25962).
     *
     * @return list<array{0: int, 1: string}>
     */
    public function uncloneableClassIdsForGuard(): array
    {
        $out = [];
        foreach ($this->classIdToName as $id => $name) {
            if ($this->classOrAncestorDeniesClone($id)) {
                $out[] = [$id, $name];
            }
        }

        return $out;
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
     *
     * Property names are case-sensitive (zend_builtin_functions.c / #23532).
     */
    public function propertyExistsFromScope(int $scopeClassId, string $property): bool
    {
        $lc = strtolower($property);
        $currentId = $scopeClassId;
        for ($depth = 0; $depth < 64; ++$depth) {
            foreach ($this->properties[$currentId] ?? [] as $propset) {
                if ($propset[1] !== $property) {
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
                $entry = $this->staticPropertyGlobals[$currentId][$lc];
                $declared = $entry['displayName'] ?? $lc;
                if ($declared !== $property) {
                    return false;
                }
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
     * Instance properties visible to foreach from $scopeClassLc (null = global / external).
     *
     * Matches get_object_vars() / zend_check_property_access (#23430).
     * Skips DateTime* / DateTimeZone compiler __dt_* storage (not on Zend property table) (#23432).
     *
     * @return list<array{0: int, 1: string, 2: int, 3: int}>
     */
    public function instancePropertySetsVisibleFromScope(int $classId, ?string $scopeClassLc): array
    {
        $props = $this->properties[$classId] ?? [];
        if ([] === $props) {
            return [];
        }
        $scopeId = null;
        if (null !== $scopeClassLc && '' !== $scopeClassLc) {
            $lc = strtolower(ltrim($scopeClassLc, '\\'));
            if (isset($this->classes[$lc])) {
                $scopeId = $this->classes[$lc];
            }
        }
        $visible = [];
        foreach ($props as $propset) {
            $name = $propset[1];
            // php-src ext/date — date state is C, not iterable PHP props (#23432).
            if (\PHPCompiler\VM\DateTimeSupport::isInternalStorageProperty($name)) {
                continue;
            }
            $meta = $this->instancePropertyVisibilityMeta($classId, $name);
            if (null === $meta) {
                $visible[] = $propset;
                continue;
            }
            $vis = $meta['visibility'];
            if (MethodVisibility::isPublic($vis)) {
                $visible[] = $propset;
                continue;
            }
            if (null === $scopeId) {
                continue;
            }
            if ($this->propertyVisibleFromScopeId($scopeId, $vis, $meta['declaringClassId'])) {
                $visible[] = $propset;
            }
        }

        return $visible;
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

    /**
     * Typed (incl. explicit mixed) slots raise on UNDEF; untyped warn + NULL (#22021).
     */
    public function markPropertyTypedInitGuard(int $classId, string $name): void
    {
        foreach ($this->properties[$classId] ?? [] as $propset) {
            if ($propset[1] !== $name) {
                continue;
            }
            $this->typedPropertyInitGuardSlots[$classId][$propset[3]] = true;

            return;
        }
    }

    public function propertySlotRequiresTypedInitGuard(int $classId, int $slotIndex): bool
    {
        return isset($this->typedPropertyInitGuardSlots[$classId][$slotIndex]);
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
        if ('domnode' === $lcname && CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
            $this->seedExternalClassConstants($id, [
                'document_position_disconnected' => DomConstants::DOCUMENT_POSITION_DISCONNECTED,
                'document_position_preceding' => DomConstants::DOCUMENT_POSITION_PRECEDING,
                'document_position_following' => DomConstants::DOCUMENT_POSITION_FOLLOWING,
                'document_position_contains' => DomConstants::DOCUMENT_POSITION_CONTAINS,
                'document_position_contained_by' => DomConstants::DOCUMENT_POSITION_CONTAINED_BY,
                'document_position_implementation_specific' => DomConstants::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC,
            ]);
        }
        if ('dateperiod' === $lcname) {
            // php-src REGISTER_DATEPERIOD_CLASS_CONST_LONG (#20071, ext/date/php_date.c).
            $this->seedExternalClassConstants($id, [
                'exclude_start_date' => \PHPCompiler\VM\DatePeriodSupport::OPTION_EXCLUDE_START_DATE,
                'include_end_date' => \PHPCompiler\VM\DatePeriodSupport::OPTION_INCLUDE_END_DATE,
            ]);
        }
        if ('ziparchive' === $lcname && CompilerVersion::supportsZip()) {
            // php-src ext/zip/php_zip.c REGISTER_ZIPARCHIVE_CLASS_CONST_* (#20712).
            // Host PHP often lacks ext-zip; seed from ZipArchiveConstants for AOT/JIT.
            $this->seedExternalClassConstants($id, ZipArchiveConstants::CLASS_CONSTANTS);
        }
    }

    /**
     * @param array<string, int|float|bool|string|null> $constants
     */
    private function seedExternalClassConstants(int $id, array $constants): void
    {
        foreach ($constants as $name => $value) {
            // Seed tables use lowercase map keys; PHP declared names are UPPER_SNAKE (#25929).
            $key = strtoupper((string) $name);
            if (isset($this->classConstants[$id][$key])) {
                continue;
            }
            $this->classConstDisplayNames[$id][$key] = $key;
            if (\is_string($value)) {
                $this->classConstants[$id][$key] = [
                    'type' => Variable::TYPE_STRING,
                    'value' => $value,
                ];
            } elseif (\is_float($value)) {
                $this->classConstants[$id][$key] = [
                    'type' => Variable::TYPE_NATIVE_DOUBLE,
                    'value' => $value,
                ];
            } elseif (\is_bool($value)) {
                $this->classConstants[$id][$key] = [
                    'type' => Variable::TYPE_NATIVE_BOOL,
                    'value' => $value,
                ];
            } elseif (null === $value) {
                $this->classConstants[$id][$key] = [
                    'type' => Variable::TYPE_NULL,
                    'value' => null,
                ];
            } else {
                $this->classConstants[$id][$key] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => (int) $value,
                ];
            }
        }
    }

    public function isExternalOnlyClass(int $classId): bool
    {
        return isset($this->externalOnlyClassIds[$classId]);
    }

    /**
     * Engine classes with ZEND_ACC_NO_DYNAMIC_PROPERTIES (Closure/Fiber/Generator/WeakMap; #26371).
     * Living Dom\* are NOT sealed — php-src 8.4/8.5 allow Deprecated+write (#26566; re-#26055).
     */
    private static function externalClassRejectsDynamicProperties(string $lcname): bool
    {
        return \in_array($lcname, ['closure', 'fiber', 'generator', 'weakmap'], true);
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
        $this->classes[$lcname] = $id;
        // Prefer Zend display spelling for builtins looked up as lowercase (#26885).
        if ('stdclass' === $lcname) {
            $displayName = 'stdClass';
            $this->allowsDynamicPropertiesClassIds[$id] = true;
        }
        // classIdToName must keep display spelling (stdClass, not stdclass) for
        // get_class()/get_debug_type()/::class (#23641, #26885). Lookup keys stay in $classes.
        $this->classIdToName[$id] = $displayName;
        if (self::externalClassRejectsDynamicProperties($lcname)) {
            $this->noDynamicPropertiesClassIds[$id] = true;
        }
        $this->ensureExternalClassConstants($id, $lcname);
        $this->seedExternalClassProperties($id, $lcname);
        $this->seedThrowableExternalClass($id, $lcname, $displayName);
        if ('reflectionattribute' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'args', Variable::TYPE_HASHTABLE);
        }
        if ('reflectionclass' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
        }
        if ('reflectionmethod' === $lcname) {
            $this->setClassParentName('ReflectionMethod', 'ReflectionFunctionAbstract');
            $this->defineProperty($id, 'class', Variable::TYPE_STRING);
            $this->defineProperty($id, 'name', Variable::TYPE_STRING);
        }
        if ('reflectionfunction' === $lcname) {
            $this->setClassParentName('ReflectionFunction', 'ReflectionFunctionAbstract');
            // Zend public `$name` (#22488).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME, Variable::TYPE_STRING);
        }
        if ('reflectionparameter' === $lcname) {
            // Public Zend surface: `$name` only; other slots are engine storage (#22528).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_CLASS, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_METHOD_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_FUNC_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_INDEX, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_POSITION, Variable::TYPE_NATIVE_LONG);
        }
        if ('reflectionproperty' === $lcname) {
            // Zend public surface: $name then $class (#22504).
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #27315).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PROPERTY_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_DECLARING_CLASS_NAME, Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#27315 / #27303 / #26772).
            $this->markHasConstructor($id);
        }
        if ('reflectionclassconstant' === $lcname) {
            // Zend public surface: $name then $class (#22503).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_CLASS, Variable::TYPE_STRING);
        }
        if ('reflectionconstant' === $lcname) {
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #27303).
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'constant', Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#27303 / #26772).
            $this->markHasConstructor($id);
        }
        if ('phptoken' === $lcname) {
            // php-src PhpToken public $id/$text/$line/$pos (#27263 / #6794).
            $this->defineProperty($id, \PHPCompiler\ext\tokenizer\VmPhpToken::PROP_ID, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\ext\tokenizer\VmPhpToken::PROP_TEXT, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\ext\tokenizer\VmPhpToken::PROP_LINE, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\ext\tokenizer\VmPhpToken::PROP_POS, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;
            $this->defineMethodVisibility($id, '__construct', $pub);
            $this->defineMethodVisibility($id, 'tokenize', $pubStatic);
            $this->defineMethodVisibility($id, 'gettokenname', $pub, 'getTokenName');
            $this->defineMethodVisibility($id, 'is', $pub);
            $this->defineMethodVisibility($id, 'isignorable', $pub, 'isIgnorable');
            $this->defineMethodVisibility($id, '__tostring', $pub, '__toString');
        }
        if ('reflectionenum' === $lcname) {
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #27314).
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#27314 / #27303 / #26772).
            $this->markHasConstructor($id);
        }
        if ('reflectionenumunitcase' === $lcname) {
            // Zend public surface: $name then $class (#22505; was internal-only enumClass).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_ENUM_CLASS_NAME, Variable::TYPE_STRING);
        }
        if ('reflectionenumbackedcase' === $lcname) {
            $this->setClassParentName('ReflectionEnumBackedCase', 'ReflectionEnumUnitCase');
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_ENUM_CLASS_NAME, Variable::TYPE_STRING);
        }
        // HashContext JIT handle slot must exist before allocate() (ext/hash/JitHashContext.php, #3357).
        if ('hashcontext' === $lcname) {
            $this->defineProperty($id, '__hcId', Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, '__hcAlgo', Variable::TYPE_STRING);
            $this->defineProperty($id, '__hcBuf', Variable::TYPE_STRING);
        }
        if ('phpcompiler\vm\context' === $lcname) {
            $this->defineProperty($id, 'runtime', Variable::TYPE_OBJECT);
            $this->defineProperty($id, 'errors', Variable::TYPE_OBJECT);
            $this->defineProperty($id, 'scriptStack', Variable::TYPE_OBJECT);
        }
        if ('phpcompiler\runtime' === $lcname) {
            $selfHostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
            $m5DriverHost = getenv('PHP_COMPILER_M5_DRIVER_HOST');
            $m5Host = '1' === $m5DriverHost || 'true' === strtolower((string) $m5DriverHost);
            // SELFHOST_AOT normally keeps only `mode` — full Runtime property init segfaults
            // LLVM 9 when NestedJIT-lowering `new Runtime()` (#2600). M5 argv / gen-0 seed uses
            // C-floor initParsePipeline/initCompiler/initVmContext instead (#26756), so the
            // parse-spine slots must exist or propertyStore writes nowhere and parse SEGV on null.
            if (('1' === $selfHostAot || 'true' === strtolower((string) $selfHostAot)) && !$m5Host) {
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
                        // C-floor RuntimeInitParsePipeline also stores these annotators (#26756).
                        'confusableBuiltinTypeHintCheck',
                        'abstractEnumMarker',
                        'sealedClassAnnotator',
                        'staticClassAnnotator',
                    ] as $prop
                ) {
                    $this->defineProperty($id, $prop, Variable::TYPE_OBJECT);
                }
                $this->defineProperty($id, 'modules', Variable::TYPE_HASHTABLE);
                $this->defineProperty($id, 'mode', Variable::TYPE_NATIVE_LONG);
                // C-floor sets true so parse() skips prepare list-unpack SEGV (#26756).
                $this->defineProperty($id, 'm5ArgvIdentityParsePrepare', Variable::TYPE_NATIVE_BOOL);
            }
        }
        if ('closure' === $lcname) {
            // Invoke metadata for indirect holders (array elements, properties; issue #72).
            $this->defineProperty($id, '__closure_target', Variable::TYPE_STRING);
            $this->defineProperty($id, FiberHelper::TARGET_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::BOUND_THIS_PROPERTY, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::BOUND_SCOPE_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::IS_METHOD_PROPERTY, Variable::TYPE_NATIVE_BOOL);
            if (\PHPCompiler\CompilerVersion::supportsClosureGetCurrent()) {
                $this->defineMethodVisibility(
                    $id,
                    'getcurrent',
                    \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
                );
            }
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
        if ('dateinterval' === $lcname) {
            foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_LONG);
            }
            $this->defineProperty($id, 'f', Variable::TYPE_VALUE);
            // php-src DateInterval::$days is int|false — VALUE slot holds either (#27309).
            $this->defineProperty($id, 'days', Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        if ('datetimeimmutable' === $lcname || 'datetime' === $lcname) {
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::TS_PROPERTY, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::MICROSECOND_PROPERTY, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::TZ_PROPERTY, Variable::TYPE_STRING);
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        if ('datetimezone' === $lcname) {
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::TZ_NAME_PROPERTY, Variable::TYPE_STRING);
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        if ('dateperiod' === $lcname) {
            $this->ensureTraversableBuiltinInterfaces();
            // php-src date.stub.php — IteratorAggregate only (#22263, #22608).
            $this->setClassInterfaces($displayName, ['IteratorAggregate']);
            // php-src REGISTER_DATEPERIOD_CLASS_CONST_LONG (#20071, ext/date/php_date.c).
            $this->seedExternalClassConstants($id, [
                'exclude_start_date' => \PHPCompiler\VM\DatePeriodSupport::OPTION_EXCLUDE_START_DATE,
                'include_end_date' => \PHPCompiler\VM\DatePeriodSupport::OPTION_INCLUDE_END_DATE,
            ]);
            // php-src @readonly write handlers — assign reject only; unset + isReadOnly Zend 8.2 (#26154).
            foreach (['start', 'current', 'end', 'interval'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_OBJECT);
                $this->markPropertyWriteReject($id, $prop);
            }
            foreach (['recurrences'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_LONG);
                $this->markPropertyWriteReject($id, $prop);
            }
            foreach (['include_start_date', 'include_end_date'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_BOOL);
                $this->markPropertyWriteReject($id, $prop);
            }
            foreach (['__dp_iter_key'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_LONG);
            }
            $this->defineProperty($id, '__dp_iter_started', Variable::TYPE_NATIVE_BOOL);
            $pubStatic = \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC;
            if (\PHPCompiler\CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
                $this->defineMethodVisibility($id, 'createfromiso8601string', $pubStatic);
            }
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['getiterator', 'getstartdate', 'getenddate', 'getdateinterval', 'getrecurrences'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        if ('domelement' === $lcname) {
            $this->defineProperty($id, 'nodeName', Variable::TYPE_STRING);
            $this->defineProperty($id, 'tagName', Variable::TYPE_STRING);
            $this->defineProperty($id, 'attributes', Variable::TYPE_VALUE);
        }
        if ('dom\\attr' === $lcname) {
            // Living Dom\Attr for thin AOT method_exists / property layout (#27108).
            foreach ([
                'nodeName', 'name', 'value', 'nodeValue',
                'namespaceURI', 'localName', 'prefix',
            ] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_STRING);
            }
            $this->defineProperty($id, 'ownerElement', Variable::TYPE_VALUE);
            $this->defineMethodVisibility($id, 'rename', \PHPCfg\Func::FLAG_PUBLIC);
        }
        if ('dom\\xmldocument' === $lcname) {
            $this->defineProperty($id, 'documentElement', Variable::TYPE_OBJECT);
            $this->defineMethodVisibility($id, 'createfromstring', \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC);
            $this->defineMethodVisibility($id, 'createattribute', \PHPCfg\Func::FLAG_PUBLIC);
        }
        if ('splobjectstorage' === $lcname) {
            $this->splObjectStorageClassId = $id;
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            // php-src ext/spl/spl_observer.stub.php — Countable + Iterator + Serializable + ArrayAccess.
            // Thin AOT TYPE_VALUE dim needs ArrayAccess so object keys avoid Illegal offset (#26787 / #24681).
            $this->ensureZendBuiltinInterfaces();
            $this->setClassInterfaces($displayName, [
                'Countable',
                'Iterator',
                'Traversable',
                'Serializable',
                'ArrayAccess',
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'attach', 'detach', 'contains', 'addall', 'removeall', 'removeallexcept',
                'gethash', 'count', 'rewind', 'valid', 'key', 'current', 'next',
                'offsetset', 'offsetget', 'offsetexists', 'offsetunset',
                'getinfo', 'setinfo',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('arrayiterator' === $lcname || 'recursivearrayiterator' === $lcname) {
            // Thin user-script AOT foreach via `__spl_ht` packed walk (#26783, #26775).
            // php-src ext/spl/spl_array.stub.php — SeekableIterator + ArrayAccess + Serializable + Countable.
            $this->ensureZendBuiltinInterfaces();
            $ifaces = [
                'SeekableIterator',
                'ArrayAccess',
                'Serializable',
                'Countable',
            ];
            if ('recursivearrayiterator' === $lcname) {
                // Zend rematerializes Countable-first + RecursiveIterator (#25796).
                $this->markInterfaceClass('RecursiveIterator');
                $this->setInterfaceExtends('RecursiveIterator', ['Iterator', 'Traversable']);
                $ifaces = [
                    'Countable',
                    'Serializable',
                    'ArrayAccess',
                    'Iterator',
                    'Traversable',
                    'SeekableIterator',
                    'RecursiveIterator',
                ];
            }
            $this->setClassInterfaces($displayName, $ifaces);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            $constants = [
                'STD_PROP_LIST' => 1,
                'ARRAY_AS_PROPS' => 2,
            ];
            if ('recursivearrayiterator' === $lcname) {
                $constants['CHILD_ARRAYS_ONLY'] = 4;
            }
            $this->seedExternalClassConstants($id, $constants);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'seek',
                'count', 'append', 'getarraycopy', 'getflags', 'setflags',
                'offsetget', 'offsetset', 'offsetexists', 'offsetunset',
                'haschildren', 'getchildren',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('arrayobject' === $lcname) {
            // Thin AOT: `__spl_ht` + IteratorAggregate foreach / ArrayAccess (#26823).
            // php-src ext/spl/spl_array.stub.php — IteratorAggregate, ArrayAccess, Serializable, Countable.
            $this->ensureZendBuiltinInterfaces();
            $this->setClassInterfaces($displayName, [
                'IteratorAggregate',
                'ArrayAccess',
                'Serializable',
                'Countable',
            ]);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            $this->seedExternalClassConstants($id, [
                'STD_PROP_LIST' => 1,
                'ARRAY_AS_PROPS' => 2,
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'count', 'getarraycopy', 'getiterator',
                'getflags', 'setflags', 'append', 'exchangearray',
                'offsetget', 'offsetset', 'offsetexists', 'offsetunset',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if (
            'limititerator' === $lcname
            || 'appenditerator' === $lcname
            || 'regexiterator' === $lcname
        ) {
            // Thin AOT: snapshot / filter into `__spl_ht` at construct (#26825).
            // php-src ext/spl/spl_iterators.stub.php — OuterIterator + Iterator.
            // markHasConstructor requires isVoidJitConstructCall recognition or
            // constructed stays 0 and get_class / HT reads abort (#26825).
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('OuterIterator');
            $this->setInterfaceExtends('OuterIterator', ['Iterator', 'Traversable']);
            // Iterator-first rematerialized order (#25798).
            $this->setClassInterfaces($displayName, [
                'Iterator',
                'Traversable',
                'OuterIterator',
            ]);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            if ('regexiterator' === $lcname) {
                $this->seedExternalClassConstants($id, [
                    'USE_KEY' => 1,
                    'INVERT_MATCH' => 2,
                    'MATCH' => 0,
                    'GET_MATCH' => 1,
                    'ALL_MATCHES' => 2,
                    'SPLIT' => 3,
                    'REPLACE' => 4,
                ]);
            }
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $methods = [
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'getinneriterator',
            ];
            if ('limititerator' === $lcname) {
                $methods[] = 'seek';
                $methods[] = 'getposition';
            } elseif ('appenditerator' === $lcname) {
                $methods[] = 'append';
                $methods[] = 'getiteratorindex';
                $methods[] = 'getarrayiterator';
            } else {
                $methods[] = 'accept';
            }
            foreach ($methods as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('recursiveiteratoriterator' === $lcname) {
            // Thin AOT: LEAVES_ONLY flatten into `__spl_ht` at construct (#26775).
            // php-src ext/spl/spl_iterators.c — OuterIterator + Iterator.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('OuterIterator');
            $this->setInterfaceExtends('OuterIterator', ['Iterator', 'Traversable']);
            $this->setClassInterfaces($displayName, [
                'OuterIterator',
                'Traversable',
                'Iterator',
            ]);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            $this->seedExternalClassConstants($id, [
                'LEAVES_ONLY' => 0,
                'SELF_FIRST' => 1,
                'CHILD_FIRST' => 2,
                'CATCH_GET_CHILD' => 16,
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next',
                'getinneriterator', 'getdepth', 'setmaxdepth', 'getmaxdepth',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('splmaxheap' === $lcname || 'splminheap' === $lcname || 'splheap' === $lcname) {
            // Thin AOT: `__spl_heap` packed storage + Iterator extract-on-next (#26784).
            // Zend subclass rematerializes Countable-first (#25822).
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, ['Countable', 'Iterator']);
            $this->defineProperty($id, \PHPCompiler\VM\SplHeapJitHelper::PROP_HEAP, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplHeapJitHelper::PROP_ITER_POS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\SplHeapJitHelper::PROP_KIND, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'insert', 'extract', 'top', 'count', 'isempty',
                'rewind', 'valid', 'current', 'key', 'next',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('splpriorityqueue' === $lcname) {
            // Thin AOT: parallel `__spl_data` / `__spl_prio` + extract (#27277).
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, ['Countable', 'Iterator']);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_DATA, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_PRIO, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_ITER_POS, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'insert', 'extract', 'top', 'count', 'isempty',
                'setextractflags', 'getextractflags',
                'rewind', 'valid', 'current', 'key', 'next',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if (
            'spldoublylinkedlist' === $lcname
            || 'splqueue' === $lcname
            || 'splstack' === $lcname
        ) {
            // Thin AOT: `__spl_ht` packed deque for push/pop/shift/enqueue/dequeue (#26790).
            // Zend rematerializes Serializable-first subclass interfaces (#25797).
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, [
                'Serializable',
                'ArrayAccess',
                'Countable',
                'Traversable',
                'Iterator',
            ]);
            $this->defineProperty($id, \PHPCompiler\VM\SplDllistJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $methods = [
                '__construct', 'push', 'pop', 'shift', 'unshift',
                'top', 'bottom', 'count', 'isempty',
                'rewind', 'valid', 'current', 'key', 'next',
            ];
            if ('splqueue' === $lcname) {
                $methods = array_merge($methods, ['enqueue', 'dequeue']);
            }
            foreach ($methods as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            if ('spldoublylinkedlist' !== $lcname) {
                $this->setClassParentName($displayName, 'SplDoublyLinkedList');
            }
        }
        if ('splfixedarray' === $lcname) {
            // Thin AOT: `__spl_ht` packed storage for fromArray/count/ArrayAccess (#26793).
            // php-src ext/spl/spl_fixedarray.stub.php — IteratorAggregate + ArrayAccess + Countable + JsonSerializable.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('JsonSerializable');
            $this->setClassInterfaces($displayName, [
                'IteratorAggregate',
                'Traversable',
                'ArrayAccess',
                'Countable',
                'JsonSerializable',
            ]);
            $this->defineProperty($id, \PHPCompiler\VM\SplFixedArrayJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;
            foreach ([
                '__construct', 'count', 'getsize', 'setsize', 'toarray', 'getiterator',
                'offsetget', 'offsetset', 'offsetexists', 'offsetunset',
                'jsonserialize',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            $this->defineMethodVisibility($id, 'fromarray', $pubStatic);
        }
        if ('sensitiveparametervalue' === $lcname) {
            // Trace redaction marker — store wrapped arg for getValue() (#3351, #4621, #22487).
            // Private like Zend zend_exceptions.stub.php — json_encode must not leak (#23042).
            $this->defineProperty($id, 'value', Variable::TYPE_VALUE);
            $this->definePropertyVisibility($id, 'value', \PHPCfg\Func::FLAG_PRIVATE);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['__construct', 'getvalue', '__debuginfo'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('bcmath\number' === $lcname && CompilerVersion::supportsBcmath()) {
            // php-src ext/bcmath/bcmath.stub.php — readonly value/scale (#24683, #7220).
            $this->defineProperty($id, \PHPCompiler\ext\bcmath\VmBcMathNumber::PROP_VALUE, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\ext\bcmath\VmBcMathNumber::PROP_SCALE, Variable::TYPE_NATIVE_LONG);
            $this->setClassReadonly($id, true);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'add', 'sub', 'mul', 'div', 'mod', 'divmod', 'powmod', 'pow',
                'sqrt', 'floor', 'ceil', 'round', 'compare', '__tostring',
                '__serialize', '__unserialize',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('weakreference' === $lcname) {
            $this->weakReferenceClassId = $id;
            // zend_weakrefs.c — clone_obj unset (#25962).
            $this->markDenyClone($id);
            $this->defineProperty($id, '__weak_target', Variable::TYPE_VALUE);
            $this->defineMethodVisibility(
                $id,
                'create',
                \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
            );
        }
        if ('random\\randomizer' === $lcname) {
            $this->defineProperty($id, 'engine', Variable::TYPE_OBJECT);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'nextint', 'getint', 'getbytes', 'shufflearray', 'shufflebytes',
                'pickarraykeys', '__serialize', '__unserialize',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            if (\PHPCompiler\CompilerVersion::supportsRandomIntervalBoundary()) {
                foreach (['nextfloat', 'getfloat', 'getbytesfromstring'] as $method) {
                    $this->defineMethodVisibility($id, $method, $pub);
                }
            }
        }
        if ('random\\engine\\mt19937' === $lcname) {
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['__construct', 'generate', '__serialize', '__unserialize', '__debuginfo'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('weakmap' === $lcname) {
            $this->weakMapClassId = $id;
            $this->defineProperty($id, '__weak_map', Variable::TYPE_HASHTABLE);
            // Zend/zend_weakrefs.c — ArrayAccess + Countable + IteratorAggregate (#22267).
            $this->setClassInterfaces($displayName, ['arrayaccess', 'countable', 'iteratoraggregate']);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'offsetset', 'offsetget', 'offsetexists', 'offsetunset', 'count', 'getiterator',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('streambucket' === $lcname) {
            // PHP 8.4+ final StreamBucket (user_filters.stub.php; #26923). ≤8.3 uses stdClass (#10325).
            if (\PHPCompiler\CompilerVersion::supportsStreamBucketClass()) {
                $this->classIdToName[$id] = 'StreamBucket';
                $this->defineProperty($id, 'bucket', Variable::TYPE_NATIVE_LONG);
                $this->defineProperty($id, 'data', Variable::TYPE_STRING);
                $this->defineProperty($id, 'datalen', Variable::TYPE_NATIVE_LONG);
                $this->defineProperty($id, 'dataLength', Variable::TYPE_NATIVE_LONG);
                $this->noDynamicPropertiesClassIds[$id] = true;
            }
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
        // M5 C-floor wires these onto Parser for FORCE_PARSER NestedJIT (#27426).
        // Do not allocate PhpParser\Parser\Php7 here — that class lookup SEGVd argv rebuild
        // at c:main_before_php; use a lightweight peer (see RuntimeInitParsePipeline).
        if ('phpcfg\\parser' === $lcname) {
            foreach (['astParser', 'astTraverser', 'magicStringResolver'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_OBJECT);
            }
        }
        // M5ParserAstPeer method slots for NestedJIT under FORCE_PARSER (#27426).
        if ('phpcompiler\\jit\\m5parserastpeer' === $lcname || 'm5parserastpeer' === $lcname) {
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['parse', 'traverse', 'addvisitor', 'begincompilationunit'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
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
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
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
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
            $this->setEnumBackedType($id, 'int');
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
                $backing->int(\PHPCompiler\ext\standard\VmRoundMode::roundModeIntFromCaseName($caseName));
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
        if ('arraypadtype' === $lcname && \PHPCompiler\CompilerVersion::supportsArrayPadTypeEnum()) {
            $this->enums[$lcname] = true;
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
            foreach (['Positive', 'Negative'] as $caseName) {
                $backing = new VMVariable();
                $backing->null();
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
        if ('parseurl' === $lcname) {
            $this->enums[$lcname] = true;
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
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
     * Seed Exception/Error hierarchy layout + ctor for user-script AOT (#23641).
     *
     * External Throwable classes previously allocated with zero property slots, so
     * getMessage()/uncaught printers read past the object (rc=134) and __construct
     * was never called (hasConstructor false → empty message).
     *
     * php-src: Zend/zend_exceptions.stub.php — VM SSOT {@see \PHPCompiler\VM\BuiltinClasses::registerThrowableClass}
     */
    private function seedThrowableExternalClass(int $classId, string $lcname, string $displayName): void
    {
        $canonical = ThrowableManifest::nameForLc($lcname);
        if (null === $canonical || !ThrowableManifest::isAdvertised($canonical)) {
            return;
        }

        $parentName = ThrowableManifest::parentName($canonical);
        if (null !== $parentName) {
            $this->setClassParentName($canonical, $parentName);
        } else {
            // Exception / Error implement Throwable directly (#23641).
            if (!isset($this->classes[ThrowableManifest::LC_THROWABLE])) {
                $this->lookup('Throwable');
            }
            $this->markInterfaceClass('Throwable');
            $this->setClassInterfaces($canonical, [ThrowableManifest::LC_THROWABLE]);
        }

        // Same slot order as VM BuiltinClasses::registerThrowableClass.
        foreach (
            [
                ExceptionSupport::PROP_MESSAGE => Variable::TYPE_STRING,
                ExceptionSupport::PROP_CODE => Variable::TYPE_NATIVE_LONG,
                ExceptionSupport::PROP_FILE => Variable::TYPE_STRING,
                ExceptionSupport::PROP_LINE => Variable::TYPE_NATIVE_LONG,
                ExceptionSupport::PROP_PREVIOUS => Variable::TYPE_VALUE,
                ExceptionSupport::PROP_TRACE => Variable::TYPE_HASHTABLE,
            ] as $prop => $type
        ) {
            if (!$this->hasProperty($classId, $prop)) {
                $this->defineProperty($classId, $prop, $type);
            }
        }

        $isErrorFamily = ThrowableManifest::LC_ERROR === $lcname
            || ThrowableManifest::isDescendantOf($lcname, ThrowableManifest::LC_ERROR);
        if (!$isErrorFamily && !$this->hasProperty($classId, ExceptionSupport::PROP_STRING)) {
            $this->defineProperty($classId, ExceptionSupport::PROP_STRING, Variable::TYPE_STRING);
        }
        if (
            ThrowableManifest::LC_ERROR_EXCEPTION === $lcname
            && !$this->hasProperty($classId, ExceptionSupport::PROP_SEVERITY)
        ) {
            $this->defineProperty($classId, ExceptionSupport::PROP_SEVERITY, Variable::TYPE_NATIVE_LONG);
        }

        $this->markHasConstructor($classId);
        $pub = \PHPCfg\Func::FLAG_PUBLIC;
        foreach (
            [
                '__construct',
                'getmessage',
                'getcode',
                'getfile',
                'getline',
                'getprevious',
                'gettrace',
                'gettraceasstring',
                '__tostring',
                '__wakeup',
            ] as $method
        ) {
            $this->defineMethodVisibility($classId, $method, $pub);
        }
        if (ThrowableManifest::LC_ERROR_EXCEPTION === $lcname) {
            $this->defineMethodVisibility($classId, 'getseverity', $pub);
        }
        // zend_exceptions.c — clone_obj = NULL on Exception/Error roots (#25870).
        if (ThrowableManifest::LC_EXCEPTION === $lcname || ThrowableManifest::LC_ERROR === $lcname) {
            $this->markDenyClone($classId);
        }

        // Keep display name (LogicException) not lowercase for get_class / fatals (#23641).
        $this->classIdToName[$classId] = $canonical !== '' ? $canonical : $displayName;
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
        if (str_starts_with($lcClass, 'phpcfg\\parser')) {
            if (in_array($lcName, ['astparser', 'asttraverser', 'magicstringresolver'], true)) {
                return Variable::TYPE_OBJECT;
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
                'confusablebuiltintypehintcheck',
                'abstractenummarker',
                'sealedclassannotator',
                'staticclassannotator',
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

        // HashContext JIT handle (ext/hash/JitHashContext.php, #3357).
        if ('hashcontext' === $lcClass && '__hcid' === $lcName) {
            return Variable::TYPE_NATIVE_LONG;
        }
        if ('hashcontext' === $lcClass && ('__hcalgo' === $lcName || '__hcbuf' === $lcName)) {
            return Variable::TYPE_STRING;
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

        if ($this->isInterfaceClassLc($classLc)) {
            $chain = $this->expandInterfaceLc($classLc);
        } else {
            $chain = [];
            $currentLc = $classLc;
            while (null !== $currentLc) {
                array_unshift($chain, $currentLc);
                $currentLc = $this->classParentLc[$currentLc] ?? null;
            }
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

    public function definePropertyAsymmetricExplicitRead(int $classId, string $name): void
    {
        $this->propertyAsymmetricExplicitRead[$classId][strtolower($name)] = true;
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

    public function propertyAsymmetricExplicitRead(int $classId, string $name): bool
    {
        return $this->propertyAsymmetricExplicitRead[$classId][strtolower($name)] ?? false;
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

    public function defineStaticPropertyAsymmetricExplicitRead(int $classId, string $name): void
    {
        $this->staticPropertyAsymmetricExplicitRead[$classId][strtolower($name)] = true;
    }

    public function staticPropertySetVisibility(int $classId, string $name): int
    {
        return $this->staticPropertySetVisibility[$classId][strtolower($name)] ?? 0;
    }

    public function staticPropertyGetVisibility(int $classId, string $name): int
    {
        return $this->staticPropertyGetVisibility[$classId][strtolower($name)] ?? 0;
    }

    public function staticPropertyAsymmetricExplicitRead(int $classId, string $name): bool
    {
        return $this->staticPropertyAsymmetricExplicitRead[$classId][strtolower($name)] ?? false;
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

    /**
     * Resolve inherited __construct proxy for subclasses that omit their own ctor (#23974 / #23641).
     *
     * Walks {@see parentClassLc} until a registered `parent::__construct` JIT proxy is found
     * (Exception/Error hierarchy is pre-registered on Context).
     */
    public function inheritedConstructorProxyLc(string $className): ?string
    {
        $parentLc = $this->parentClassLc($className);
        while (null !== $parentLc) {
            $proxy = $parentLc.'::__construct';
            if ($this->context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            $parentLc = $this->parentClassLc($parentLc);
        }

        return null;
    }

    public function defineProperty(int $classId, string $name, int $type): void
    {
        $nameLc = strtolower($name);
        foreach ($this->properties[$classId] ?? [] as $idx => $existing) {
            if (strtolower($existing[1]) !== $nameLc) {
                continue;
            }
            $declaringId = $this->instancePropertyDeclaringClassId[$classId][$nameLc] ?? $classId;
            $traitSourceId = $this->instancePropertyTraitSourceId[$classId][$nameLc] ?? null;
            if ($declaringId === $classId && null === $traitSourceId) {
                // Same class already declared this property — keep the first slot.
                return;
            }
            // Class body redeclares a trait property: reuse the slot; finish with
            // assertClassTraitInstancePropertyMerge after defaults/flags (#22850, #26593).
            $originId = $traitSourceId ?? $declaringId;
            $this->pendingTraitInstancePropertyOverride[$classId][$nameLc] = $this->snapshotInstanceProperty(
                $classId,
                $originId,
                $existing
            );
            $this->properties[$classId][$idx] = [
                $existing[0], $name, $type, $existing[3],
            ];
            $this->instancePropertyDeclaringClassId[$classId][$nameLc] = $classId;
            unset($this->instancePropertyTraitSourceId[$classId][$nameLc]);
            // Drop trait default so a class body without an initializer stays unset.
            unset($this->propertyDefaults[$classId][$existing[3]]);
            unset($this->runtimePropertyNewDefaults[$classId][$existing[3]]);

            return;
        }
        if (!isset($this->propNameMap[$name])) {
            $this->propNameMap[$name] = count($this->propNameMap);
        }
        if (!isset($this->properties[$classId])) {
            $this->properties[$classId] = [];
        }
        $this->properties[$classId][] = [
            $this->propNameMap[$name], $name, $type, count($this->properties[$classId]),
        ];
        $this->instancePropertyDeclaringClassId[$classId][$nameLc] = $classId;
    }

    /**
     * After class-body DECLARE_PROPERTY metadata is applied, merge or fatal vs trait (#22850).
     */
    public function assertClassTraitInstancePropertyMerge(int $classId, string $name): void
    {
        $nameLc = strtolower($name);
        $pending = $this->pendingTraitInstancePropertyOverride[$classId][$nameLc] ?? null;
        if (null === $pending) {
            return;
        }
        unset($this->pendingTraitInstancePropertyOverride[$classId][$nameLc]);
        $current = $this->findInstancePropertySet($classId, $name);
        if (null === $current) {
            return;
        }
        if ($this->instancePropertySnapshotsCompatible(
            $pending,
            $this->snapshotInstanceProperty($classId, $classId, $current)
        )) {
            return;
        }
        throw new \LogicException(TraitCompositionConflictMessage::incompatibleClassTraitProperty(
            $this->classNameForId($classId),
            $this->classNameForId((int) $pending['declaringId']),
            $name
        ));
    }

    /**
     * @param array{0: int, 1: string, 2: int, 3: int} $propset
     * @return array<string, mixed>
     */
    private function snapshotInstanceProperty(int $classId, int $declaringId, array $propset): array
    {
        $name = $propset[1];
        $slot = $propset[3];

        return [
            'declaringId' => $declaringId,
            'name' => $name,
            'type' => $propset[2],
            'visibility' => $this->propertyVisibility($classId, $name),
            'setVisibility' => $this->propertySetVisibility($classId, $name),
            'getVisibility' => $this->propertyGetVisibility($classId, $name),
            'readonly' => $this->isPropertyReadonly($classId, $name),
            'default' => $this->propertyDefaults[$classId][$slot] ?? null,
            'dnf' => $this->dnfArmsForProperty($classId, $name),
            'typedGuard' => isset($this->typedPropertyInitGuardSlots[$classId][$slot]),
        ];
    }

    /**
     * @return array{0: int, 1: string, 2: int, 3: int}|null
     */
    private function findInstancePropertySet(int $classId, string $name): ?array
    {
        $nameLc = strtolower($name);
        foreach ($this->properties[$classId] ?? [] as $propset) {
            if (strtolower($propset[1]) === $nameLc) {
                return $propset;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function instancePropertySnapshotsCompatible(array $left, array $right): bool
    {
        if (MethodVisibility::mask((int) $left['visibility']) !== MethodVisibility::mask((int) $right['visibility'])) {
            return false;
        }
        if ((bool) $left['readonly'] !== (bool) $right['readonly']) {
            return false;
        }
        if ((int) $left['setVisibility'] !== (int) $right['setVisibility']
            || (int) $left['getVisibility'] !== (int) $right['getVisibility']) {
            return false;
        }
        if ((bool) $left['typedGuard'] !== (bool) $right['typedGuard']) {
            return false;
        }
        if ($left['dnf'] != $right['dnf']) {
            return false;
        }

        return $this->jitPropertyDefaultEntriesCompatible($left['default'] ?? null, $right['default'] ?? null);
    }

    /**
     * @param array{0: int, 1: string, 2: int, 3: int} $classPropset
     * @param array{0: int, 1: string, 2: int, 3: int} $traitPropset
     */
    private function jitInstancePropertiesCompatible(
        int $classId,
        int $traitId,
        string $name,
        array $classPropset,
        array $traitPropset,
    ): bool {
        return $this->instancePropertySnapshotsCompatible(
            $this->snapshotInstanceProperty(
                $classId,
                $this->instancePropertyDeclaringClassId[$classId][strtolower($name)] ?? $classId,
                $classPropset
            ),
            $this->snapshotInstanceProperty($traitId, $traitId, $traitPropset)
        );
    }

    /**
     * @param array<string, mixed> $traitEntry
     */
    private function jitStaticPropertiesCompatible(
        int $classId,
        int $traitId,
        string $name,
        array $traitEntry,
    ): bool {
        $existing = $this->staticPropertyGlobals[$classId][$name];

        return $this->jitStaticPropertyEntriesCompatible(
            $existing,
            (int) ($this->staticPropertyVisibility[$classId][$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
            (int) ($this->staticPropertySetVisibility[$classId][$name] ?? 0),
            (int) ($this->staticPropertyGetVisibility[$classId][$name] ?? 0),
            !empty($this->staticPropertyAsymmetricExplicitRead[$classId][$name]),
            (int) $traitEntry['type'],
            $traitEntry['default'] ?? null,
            !empty($traitEntry['typedWithoutDefault']),
            (int) ($this->staticPropertyVisibility[$traitId][$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
            (int) ($this->staticPropertySetVisibility[$traitId][$name] ?? 0),
            (int) ($this->staticPropertyGetVisibility[$traitId][$name] ?? 0),
            !empty($this->staticPropertyAsymmetricExplicitRead[$traitId][$name]),
        );
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function jitStaticPropertyEntriesCompatible(
        array $existing,
        int $existingVisibility,
        int $existingSetVisibility,
        int $existingGetVisibility,
        bool $existingAsym,
        int $incomingType,
        ?VMVariable $incomingDefault,
        bool $incomingTypedWithoutDefault,
        int $incomingVisibility,
        int $incomingSetVisibility = 0,
        int $incomingGetVisibility = 0,
        bool $incomingAsym = false,
    ): bool {
        if (MethodVisibility::mask($existingVisibility) !== MethodVisibility::mask($incomingVisibility)) {
            return false;
        }
        if ($existingSetVisibility !== $incomingSetVisibility
            || $existingGetVisibility !== $incomingGetVisibility
            || $existingAsym !== $incomingAsym) {
            return false;
        }
        if ((int) $existing['type'] !== $incomingType) {
            return false;
        }
        $existingTypedWithoutDefault = !empty($existing['typedWithoutDefault']);
        $existingDefault = $existing['default'] ?? null;
        $existingDefaultVar = $existingDefault instanceof VMVariable ? $existingDefault : null;
        $incomingDefaultVar = $incomingDefault;

        return TraitPropertyCompatibility::defaultsCompatible(
            $existingTypedWithoutDefault ? null : $existingDefaultVar,
            $existingTypedWithoutDefault || (
                null !== $existingDefaultVar && (
                    $existingDefaultVar->hasDeclaredTypeConstraint()
                    || (null !== $existingDefaultVar->declaredTypeLabel && '' !== $existingDefaultVar->declaredTypeLabel)
                )
            ),
            $incomingTypedWithoutDefault ? null : $incomingDefaultVar,
            $incomingTypedWithoutDefault || (
                null !== $incomingDefaultVar && (
                    $incomingDefaultVar->hasDeclaredTypeConstraint()
                    || (null !== $incomingDefaultVar->declaredTypeLabel && '' !== $incomingDefaultVar->declaredTypeLabel)
                )
            ),
        );
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private function jitPropertyDefaultEntriesCompatible($left, $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            // Untyped missing ≡ null default entry when the other is null-typed.
            $present = $left ?? $right;
            if (is_array($present) && ($present['type'] ?? null) === Variable::TYPE_NULL) {
                return true;
            }

            return false;
        }
        if (!is_array($left) || !is_array($right)) {
            return false;
        }
        if (!empty($left['emptyArray']) || !empty($right['emptyArray'])) {
            return !empty($left['emptyArray']) && !empty($right['emptyArray']);
        }
        if (($left['type'] ?? null) !== ($right['type'] ?? null)) {
            return false;
        }
        if (isset($left['vmTable']) || isset($right['vmTable'])) {
            return $this->vmHashTablesCompatible(
                $left['vmTable'] ?? null,
                $right['vmTable'] ?? null
            );
        }
        foreach (['value', 'string', 'integer', 'float', 'bool'] as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Trait/class property default array tables must match element-wise (#24086).
     */
    private function vmHashTablesCompatible($left, $right): bool
    {
        if (!$left instanceof \PHPCompiler\VM\HashTable || !$right instanceof \PHPCompiler\VM\HashTable) {
            return false;
        }
        if ($left->getNumElements() !== $right->getNumElements()) {
            return false;
        }
        $rightByKey = [];
        foreach ($right->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $rightByKey[$this->vmScalarFingerprint($keyVar)] = $this->vmScalarFingerprint($valueVar);
        }
        foreach ($left->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $this->vmScalarFingerprint($keyVar);
            if (!array_key_exists($key, $rightByKey)) {
                return false;
            }
            if ($rightByKey[$key] !== $this->vmScalarFingerprint($valueVar)) {
                return false;
            }
        }

        return true;
    }

    private function vmScalarFingerprint(VMVariable $value): string
    {
        $resolved = $value->resolveIndirect();
        switch ($resolved->type) {
            case VMVariable::TYPE_NULL:
                return 'n';
            case VMVariable::TYPE_INTEGER:
                return 'i:'.$resolved->toInt();
            case VMVariable::TYPE_FLOAT:
                return 'f:'.$resolved->toFloat();
            case VMVariable::TYPE_BOOLEAN:
                return 'b:'.($resolved->toBool() ? '1' : '0');
            case VMVariable::TYPE_STRING:
                return 's:'.$resolved->toString();
            case VMVariable::TYPE_ARRAY:
                $parts = [];
                foreach ($resolved->toArray()->iterateKeyed(true) as [$k, $v]) {
                    $parts[] = $this->vmScalarFingerprint($k).'='.$this->vmScalarFingerprint($v);
                }

                return 'a:'.implode(',', $parts);
            default:
                return 'u:'.$resolved->type;
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{type: int, value?: mixed, global?: string, vmTable?: \PHPCompiler\VM\HashTable}
     */
    private function propertyDefaultConstEntry(array $entry): array
    {
        if (isset($entry['global'])) {
            return ['type' => $entry['type'], 'global' => $entry['global']];
        }
        if (isset($entry['vmTable'])) {
            return ['type' => $entry['type'], 'vmTable' => $entry['vmTable']];
        }

        return ['type' => $entry['type'], 'value' => $entry['value']];
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

    public function assertClassOwnInstancePropertyAllowed(int $classId, string $name): void
    {
        $nameLc = strtolower($name);
        foreach ($this->properties[$classId] ?? [] as $existing) {
            if (strtolower($existing[1]) !== $nameLc) {
                continue;
            }
            $declaringId = $this->instancePropertyDeclaringClassId[$classId][$nameLc] ?? $classId;
            if ($declaringId !== $classId) {
                throw new \LogicException(TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                    $this->classNameForId($classId),
                    $this->classNameForId($declaringId),
                    $name
                ));
            }
        }
    }

    public function assertClassOwnStaticPropertyAllowed(int $classId, string $name): void
    {
        $key = strtolower($name);
        if (!isset($this->staticPropertyGlobals[$classId][$key])) {
            return;
        }
        $declaringId = $this->staticPropertyDeclaringClassId[$classId][$key] ?? $classId;
        if ($declaringId === $classId) {
            return;
        }
        // Compatible class redeclare is handled in defineStaticProperty (#22850).
        throw new \LogicException(TraitCompositionConflictMessage::incompatibleClassTraitProperty(
            $this->classNameForId($classId),
            $this->classNameForId($declaringId),
            $name
        ));
    }

    public function definePropertyDefault(int $classId, string $name, VMVariable $value): void
    {
        if (VMVariable::TYPE_ARRAY === $value->type) {
            foreach ($this->properties[$classId] as $propset) {
                if ($propset[1] !== $name) {
                    continue;
                }
                $table = $value->toArray();
                if (!$table instanceof \PHPCompiler\VM\HashTable) {
                    throw new \LogicException('Property array default must be a HashTable');
                }
                // Per-instance array default (Zend zend_objects.c). Empty → fresh alloc;
                // non-empty → rebuild from the folded VM table at each `new` (#24086).
                if (0 === $table->getNumElements()) {
                    $this->propertyDefaults[$classId][$propset[3]] = [
                        'propertyType' => $propset[2],
                        'type' => Variable::TYPE_HASHTABLE,
                        'emptyArray' => true,
                    ];
                } else {
                    $this->propertyDefaults[$classId][$propset[3]] = [
                        'propertyType' => $propset[2],
                        'type' => Variable::TYPE_HASHTABLE,
                        'vmTable' => $table,
                    ];
                }

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
                $caseKey = \PHPCompiler\ClassConstName::key(EnumCaseSupport::enumCaseNameForVariable($value));
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
        $this->constVisibility[$classId][\PHPCompiler\ClassConstName::key($name)] = ClassConstVisibility::mask($visibilityFlags);
    }

    public function defineClassConstDeprecated(
        int $classId,
        string $name,
        ?\PHPCompiler\Compiler\DeprecatedMetadata $meta
    ): void {
        if (null === $meta || !$meta->emitsRuntimeNotice()) {
            return;
        }
        $this->classConstDeprecated[$classId][\PHPCompiler\ClassConstName::key($name)] = $meta;
    }

    public function classConstDeprecatedMeta(int $classId, string $name): ?\PHPCompiler\Compiler\DeprecatedMetadata
    {
        return $this->classConstDeprecated[$classId][\PHPCompiler\ClassConstName::key($name)] ?? null;
    }

    public function constVisibility(int $classId, string $name): int
    {
        return $this->constVisibility[$classId][\PHPCompiler\ClassConstName::key($name)] ?? \PHPCfg\Func::FLAG_PUBLIC;
    }

    public function defineClassConst(int $classId, string $name, VMVariable $value): void
    {
        $key = \PHPCompiler\ClassConstName::key($name);
        $this->classConstDisplayNames[$classId][$key] = $name;
        unset($this->classConstMapGlobals[$classId]);
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
                $caseKey = \PHPCompiler\ClassConstName::key((string) ($object->enumCaseName ?? ''));
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

    /**
     * Per-class hashtable map used by dynamic class constant fetch (string key => value).
     *
     * Values are stored as actual runtime values (strings, scalars, hashtable pointers, objects),
     * so dynamic fetch can read a single {@see __value__*} entry and copy it to a result box.
     *
     * @return \PHPLLVM\Value a loaded {@see __hashtable__*} pointer
     */
    public function classConstMapPointerForId(int $classId): \PHPLLVM\Value
    {
        if (!isset($this->classConstMapGlobals[$classId])) {
            $this->defineClassConstMapGlobal($classId);
        }

        return $this->context->builder->load($this->classConstMapGlobals[$classId]);
    }

    private function defineClassConstMapGlobal(int $classId): void
    {
        $globalName = 'php_compiler_class_const_map_'.$classId;
        $htPtrType = $this->context->getTypeFromString('__hashtable__*');
        $global = $this->context->module->addGlobal($htPtrType, $globalName);
        $global->setInitializer($htPtrType->constNull());
        $this->classConstMapGlobals[$classId] = $global;
        $this->context->emitInInit(function (Context $ctx) use ($classId, $global): void {
            $ht = HashTableHelper::alloc($ctx);
            foreach ($this->classConstants[$classId] ?? [] as $key => $entry) {
                if (!\is_string($key) || '' === $key) {
                    continue;
                }
                $keyPtr = $ctx->builder->load($ctx->constantStringFromString($key));
                $valueVar = $this->jitConstantFromEntry($entry);
                HashTableHelper::setAtStringKey($ctx, $ht, $keyPtr, $valueVar);
            }
            $ctx->refcount->addref($ht);
            $ctx->builder->store($ht, $global);
        });
    }

    public function defineClassConstEnumCaseRef(
        int $holdingClassId,
        string $constName,
        int $enumClassId,
        string $caseKey
    ): void {
        $constKey = \PHPCompiler\ClassConstName::key($constName);
        $this->classConstDisplayNames[$holdingClassId][$constKey] = $constName;
        $caseKey = \PHPCompiler\ClassConstName::key($caseKey);
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
        $caseKey = \PHPCompiler\ClassConstName::key($caseKey);
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
            $caseKey = \PHPCompiler\ClassConstName::key(\PHPCompiler\VM\EnumCaseSupport::enumCaseNameForVariable($resolved));
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
            $caseKey = \PHPCompiler\ClassConstName::key(\PHPCompiler\VM\EnumCaseSupport::enumCaseNameForVariable($resolved));
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
                    if (isset($this->classConstDeprecated[$ifaceId][$name])) {
                        $this->classConstDeprecated[$classId][$name] = $this->classConstDeprecated[$ifaceId][$name];
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
            if (isset($this->classConstDeprecated[$traitId][$name])) {
                $this->classConstDeprecated[$classId][$name] = $this->classConstDeprecated[$traitId][$name];
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
                if ($this->jitStaticPropertiesCompatible($classId, $traitId, $name, $entry)) {
                    continue;
                }
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
                $entry['displayName'] ?? $name,
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
            if (isset($this->staticPropertyAsymmetricExplicitRead[$traitId][$name])) {
                $this->staticPropertyAsymmetricExplicitRead[$classId][$name]
                    = $this->staticPropertyAsymmetricExplicitRead[$traitId][$name];
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
                    if ($this->jitInstancePropertiesCompatible($classId, $traitId, $name, $existing, $propset)) {
                        continue 2;
                    }
                    $prevTraitId = $this->instancePropertyTraitSourceId[$classId][$nameLc]
                        ?? (
                            // Before #26593 remapping, declaring id was the trait.
                            (($this->instancePropertyDeclaringClassId[$classId][$nameLc] ?? $classId) !== $classId)
                                ? ($this->instancePropertyDeclaringClassId[$classId][$nameLc] ?? null)
                                : null
                        );
                    if (null === $prevTraitId) {
                        throw new \LogicException(TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                            $className,
                            $traitName,
                            $name
                        ));
                    }
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
            // zend_inheritance.c: composing class owns trait-imported properties (#26593).
            $this->instancePropertyDeclaringClassId[$classId][$nameLc] = $classId;
            $this->instancePropertyTraitSourceId[$classId][$nameLc] = $traitId;
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
                    $entry['displayName'] ?? $name,
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
                if (isset($this->staticPropertyAsymmetricExplicitRead[$parentId][$name])) {
                    $this->staticPropertyAsymmetricExplicitRead[$childId][$name]
                        = $this->staticPropertyAsymmetricExplicitRead[$parentId][$name];
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
            $declaringClass = $this->context->scope->className;
            if ('' === $declaringClass) {
                PseudoClassScope::fatalInGlobalScope('self');
            }
            $scopeLc = strtolower(ltrim($declaringClass, '\\'));
            if ($this->isTraitClass($scopeLc)) {
                $composing = $this->context->scope->traitComposingClassName;
                if ('' !== $composing && !$this->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                    $declaringClass = $composing;
                } elseif ($this->context->scope->classId > 0) {
                    $fromId = $this->classNameForId($this->context->scope->classId);
                    if ('' !== $fromId && !$this->isTraitClass(strtolower(ltrim($fromId, '\\')))) {
                        $declaringClass = $fromId;
                    }
                }
            }

            return $this->lookup($declaringClass);
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
            $declaringClass = $this->context->scope->className;
            if (null !== $block?->func?->class) {
                $funcClassLc = strtolower(ltrim($block->func->class->value, '\\'));
                if ($this->isTraitClass($funcClassLc)) {
                    $composing = $this->context->scope->traitComposingClassName;
                    if ('' !== $composing && !$this->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                        $declaringClass = $composing;
                    } elseif ($this->context->scope->classId > 0) {
                        $fromId = $this->classNameForId($this->context->scope->classId);
                        if ('' !== $fromId && !$this->isTraitClass(strtolower(ltrim($fromId, '\\')))) {
                            $declaringClass = $fromId;
                        }
                    } else {
                        $scopeLc = strtolower(ltrim($declaringClass, '\\'));
                        if ($this->isTraitClass($scopeLc)) {
                            $called = $this->context->scope->calledClassName;
                            if ('' !== $called && strtolower(ltrim($called, '\\')) !== $funcClassLc) {
                                $declaringClass = $called;
                            }
                        }
                    }
                }
            }
            $parentLc = $this->parentClassLc($declaringClass);
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $this->lookup($parentLc);
        }

        return $this->lookup($classOp->value);
    }

    public function classConstFetch(int $classId, string $constName, ?Block $block = null, ?string $classNameHint = null): Variable
    {
        $this->emitDirectTraitConstAccessErrorIfNeeded($classId, $constName, $block);
        $key = \PHPCompiler\ClassConstName::key($constName);
        $resolvedId = $this->resolveClassConstHoldingId($classId, $key);
        if (null === $resolvedId) {
            // The registry name is lowercased; PSR-4 autoload in the native
            // fallback is case-sensitive, so prefer the caller's original-case
            // literal when available (#15889 isolated unit emission).
            $native = $this->tryJitNativeClassConstant($classNameHint ?? $this->classNameForId($classId), $constName);
            if (null !== $native) {
                return $native;
            }
            $display = $classNameHint ?? $this->classNameForId($classId);
            throw new \LogicException("Undefined constant {$display}::{$constName}");
        }

        // Class constants are case-sensitive (Zend/zend_constants.c, #25910).
        $declared = $this->classConstDeclaredNameOrNull($resolvedId, $key);
        if (!\PHPCompiler\ClassConstName::matchesDeclared($constName, $declared)) {
            $display = $classNameHint ?? $this->classNameForId($classId);
            throw new \LogicException("Undefined constant {$display}::{$constName}");
        }

        if ($this->isEnumClassId($resolvedId)) {
            return $this->jitEnumCaseFromBacking($resolvedId, $key);
        }

        $dep = $this->classConstDeprecated[$resolvedId][$key] ?? null;
        if (null !== $dep) {
            $displayClass = $this->classNameForId($resolvedId);
            $displayConst = $this->classConstDisplayNames[$resolvedId][$key] ?? $constName;
            \PHPCompiler\JIT\DeprecatedCallGuard::emitClassConstFetch(
                $this->context,
                $dep,
                $displayClass,
                $displayConst
            );
        }

        return $this->jitConstantFromEntry($this->classConstants[$resolvedId][$key]);
    }

    /**
     * Find the class id that holds a fetchable class constant, skipping private
     * parent constants (Zend zend_constants.c / #19615).
     */
    public function resolveClassConstHoldingId(int $classId, string $constKey): ?int
    {
        $constKey = \PHPCompiler\ClassConstName::key($constKey);
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            if (isset($this->classConstants[$currentId][$constKey])) {
                if ($currentId === $classId) {
                    return $currentId;
                }
                $vis = $this->constVisibility($currentId, $constKey);
                // Private constants are not inherited — keep walking (#19615).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) === 0) {
                    return $currentId;
                }
            }
            $parentLc = $this->parentClassLc($this->classNameForId($currentId));
            if (null === $parentLc || !isset($this->classes[$parentLc])) {
                return null;
            }
            $currentId = $this->classes[$parentLc];
        }

        return null;
    }

    /** Native PHP class constants for nested JIT helper compiles (PasswordJitHelper → VmPassword, #9275). */
    private function tryJitNativeClassConstant(string $className, string $constName): ?Variable
    {
        $fqcn = ltrim($className, '\\');
        // Autoload like Zend's class-const fetch does: this fallback only runs
        // when the class is not part of the compiled program, so a host-side
        // (composer) definition is the right source for the value — isolated
        // helper-unit emission hits this for sibling helper classes that are
        // autoloadable but not yet loaded (#15889).
        if ('' === $fqcn || !class_exists($fqcn)) {
            return null;
        }
        try {
            $ref = new \ReflectionClassConstant($fqcn, $constName);
        } catch (\ReflectionException) {
            return null;
        }
        // Host Reflection surfaces private parent constants under the child name;
        // Zend treats child::PRIVATE as undefined (#19615).
        if ($ref->isPrivate()
            && strtolower($ref->getDeclaringClass()->getName()) !== strtolower($fqcn)
        ) {
            return null;
        }
        $raw = $ref->getValue();
        if (\is_int($raw)) {
            return $this->jitConstantFromEntry(['type' => Variable::TYPE_NATIVE_LONG, 'value' => $raw]);
        }
        if (\is_bool($raw)) {
            return $this->jitConstantFromEntry(['type' => Variable::TYPE_NATIVE_BOOL, 'value' => $raw]);
        }
        if (\is_float($raw)) {
            return $this->jitConstantFromEntry(['type' => Variable::TYPE_NATIVE_DOUBLE, 'value' => $raw]);
        }
        if (\is_string($raw)) {
            return $this->jitConstantFromEntry(['type' => Variable::TYPE_STRING, 'value' => $raw]);
        }
        if (null === $raw) {
            return $this->jitConstantFromEntry(['type' => Variable::TYPE_NULL, 'value' => null]);
        }

        return null;
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

    /** Lowercase trait FQCN that imported a composing-class constant, if any (#9187, #19629). */
    public function traitConstSourceLc(int $classId, string $constName): ?string
    {
        $key = \PHPCompiler\ClassConstName::key($constName);
        $src = $this->traitConstSources[$classId][$key] ?? null;
        if (null === $src || '' === $src) {
            return null;
        }

        return strtolower(ltrim($src, '\\'));
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
        $key = \PHPCompiler\ClassConstName::key($constKey);

        return $this->classConstDisplayNames[$classId][$key] ?? $constKey;
    }

    /** Declared casing when recorded, else null (no fallback to the lookup key) (#25910, #25929). */
    public function classConstDeclaredNameOrNull(int $classId, string $constKey): ?string
    {
        $key = \PHPCompiler\ClassConstName::key($constKey);

        return $this->classConstDisplayNames[$classId][$key] ?? null;
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
            $declaringId = $this->staticPropertyDeclaringClassId[$classId][$key] ?? $classId;
            if ($declaringId === $classId) {
                return;
            }
            $existing = $this->staticPropertyGlobals[$classId][$key];
            $incomingTypedWithoutDefault = $forceTypedWithoutDefault
                || (
                    null === $default
                    && null !== $prototype
                    && $prototype->hasDeclaredTypeConstraint()
                    && $prototype->isUndefined()
                );
            if ($this->jitStaticPropertyEntriesCompatible(
                $existing,
                (int) ($this->staticPropertyVisibility[$classId][$key] ?? \PHPCfg\Func::FLAG_PUBLIC),
                (int) ($this->staticPropertySetVisibility[$classId][$key] ?? 0),
                (int) ($this->staticPropertyGetVisibility[$classId][$key] ?? 0),
                !empty($this->staticPropertyAsymmetricExplicitRead[$classId][$key]),
                $jitType,
                $default,
                $incomingTypedWithoutDefault,
                $visibilityFlags
            )) {
                // Identical class+trait static — class wins declaring (#22850).
                $this->staticPropertyDeclaringClassId[$classId][$key] = $classId;
                $this->staticPropertyVisibility[$classId][$key] = $visibilityFlags;

                return;
            }
            $this->assertClassOwnStaticPropertyAllowed($classId, $name);

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
            $global->setInitializer(ObjectStaticPropertyInitLlvm::scalarInitializer($this, $jitType, $default));
        } else {
            $llvmTypeName = Variable::TYPE_NATIVE_DOUBLE === $jitType ? 'double' : 'int64';
            $llvmType = $this->context->getTypeFromString($llvmTypeName);
            $global = $this->context->module->addGlobal($llvmType, $globalName);
            $global->setInitializer(ObjectStaticPropertyInitLlvm::scalarInitializer($this, $jitType, $default));
        }
        $entry = [
            'type' => $jitType,
            'global' => $global,
            'default' => $default,
            'typedWithoutDefault' => $typedWithoutDefault,
            'initGlobal' => null,
            // Declared casing for property_exists() exact match (#23532).
            'displayName' => $name,
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
            ObjectStaticPropertyInitLlvm::initStringDefault($this, $global, $default);
        }
        if (Variable::TYPE_HASHTABLE === $jitType) {
            if (null === $default || VMVariable::TYPE_ARRAY === $default->type) {
                if (!ObjectStaticPropertyInitLlvm::deferHashtableInitInAot($this, $classId)) {
                    ObjectStaticPropertyInitLlvm::initHashtableEmpty($this, $global);
                }
            } else {
                throw new \LogicException(
                    'Static array property default must be an empty array literal for '.$this->classNameForId($classId).'::'.$name
                );
            }
        }
        if (Variable::TYPE_VALUE === $jitType && null !== $default && EnumCaseSupport::isEnumCaseVariable($default)) {
            ObjectStaticPropertyInitLlvm::initValueEnumCase($this, $global, $default);
        } elseif (Variable::TYPE_VALUE === $jitType && null !== $default && VMVariable::TYPE_ARRAY === $default->type) {
            ObjectStaticPropertyInitLlvm::initValueEmptyArray($this, $global);
        } elseif (Variable::TYPE_VALUE === $jitType && null !== $default && VMVariable::TYPE_NULL !== $default->type) {
            ObjectStaticPropertyInitLlvm::initValueScalarDefault($this, $global, $default);
        } elseif (Variable::TYPE_VALUE === $jitType && (null === $default || VMVariable::TYPE_NULL === $default->type)) {
            ObjectStaticPropertyInitLlvm::initValueNull($this, $global);
        }
    }

    public function staticPropertyUnset(int $classId, string $name, ?\PHPCompiler\JIT $jit = null): void
    {
        ObjectStaticPropertyLlvm::unset($this, $classId, $name, $jit);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function staticPropertyGlobalsForClass(int $classId): array
    {
        return $this->staticPropertyGlobals[$classId] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function staticPropertyGlobalEntry(int $classId, string $name): ?array
    {
        $key = strtolower($name);

        return $this->staticPropertyGlobals[$classId][$key] ?? null;
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
                'asymmetricExplicitRead' => $this->staticPropertyAsymmetricExplicitRead[$currentId][$key] ?? false,
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
                'asymmetricExplicitRead' => $this->propertyAsymmetricExplicitRead($currentId, $name),
                'declaringClassId' => $currentId,
                'declaringClassName' => $this->classNameForId($currentId),
            ];
        }

        return null;
    }

    public function staticPropertyFetch(int $classId, string $name): Variable
    {
        return ObjectStaticPropertyLlvm::fetch($this, $classId, $name);
    }

    /**
     * isset(Class::$prop) without reading uninitialized typed slots (#15112, zend_object_handlers.c).
     */
    public function compileStaticPropertyIsSet(int $classId, string $name): PHPLLVM\Value
    {
        return ObjectStaticPropertyLlvm::compileIsSet($this, $classId, $name);
    }

    /**
     * Runtime static property name (`Class::$$name`, issue #4597).
     */
    public function staticPropertyFetchDynamic(int $classId, Variable $nameVar): Variable
    {
        return ObjectStaticPropertyLlvm::fetchDynamic($this, $classId, $nameVar);
    }

    /**
     * Runtime static property name for unset (`unset(Class::$$name)`, issue #4597).
     */
    public function staticPropertyUnsetDynamic(int $classId, Variable $nameVar, ?\PHPCompiler\JIT $jit = null): void
    {
        ObjectStaticPropertyLlvm::unsetDynamic($this, $classId, $nameVar, $jit);
    }

    public function staticPropertyStore(
        \PHPLLVM\Value $global,
        Variable $value,
        int $propertyType,
        ?\PHPLLVM\Value $initGlobal = null
    ): void {
        ObjectStaticPropertyLlvm::store($this, $global, $value, $propertyType, $initGlobal);
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
        if (\PHPCompiler\VM\ResourceSupport::isHiddenPseudoClassLc($className)) {
            return $this->context->getTypeFromString('int1')->constInt(0, false);
        }
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

    public function arrayPadTypeEnumClassId(): ?int
    {
        return $this->classes['arraypadtype'] ?? null;
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
        // Enum-case runtime dispatch aborts when class_id is not an enum (#27314). Skip it
        // when the static class already declares `$name`/`$value` (ReflectionEnum::$name,
        // ReflectionClass::$name, …) — those are ordinary properties, not case singletons.
        if (
            EnumCasePropertyJitHelper::isBuiltinPropertyName($nameLc)
            && !$this->hasProperty($classId, $name)
            && [] !== ($enumIds = $this->registeredEnumClassIds())
        ) {
            return ObjectEnumCasePropertyLlvm::propertyFetchEnumCaseRuntimeDispatch($this, $obj, $nameLc, $enumIds);
        }

        return ObjectInstancePropertyLlvm::propertyFetchOrdinary($this, $obj, $class, $name, $classId);
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

    public function boxFetchedPropertyIntoValueBox(PHPLLVM\Value $destSlot, Variable $fetched): void
    {
        $propertyType = $fetched->objectPropertyType ?? $fetched->type;
        ObjectInstancePropertyLlvm::boxFetchedPropertyIntoValue($this, $destSlot, $fetched, $propertyType);
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
            ObjectInstancePropertyLlvm::boxFetchedPropertyIntoValue($this, $destSlot, $fetched, $propset[2]);
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

        // Native scalar slots must not receive a boxed __value__* (#24008). The generic
        // TYPE_VALUE store below would otherwise write the box pointer into an int64*
        // slot; later reads interpret the box header as the integer (e.g. 1025).
        if (Variable::TYPE_NATIVE_LONG === $propertyType) {
            $longVal = null;
            if (Variable::TYPE_NATIVE_LONG === $value->type) {
                $longVal = $this->context->helper->loadValue($value);
            } elseif (Variable::TYPE_VALUE === $value->type) {
                $valuePtr = Variable::KIND_VARIABLE === $value->kind
                    ? JitValueBox::pointer($this->context, $value->value)
                    : $value->value;
                $longVal = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
            } elseif (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
                $longVal = $this->context->builder->truncOrBitCast(
                    $this->context->helper->loadValue($value),
                    $this->context->getTypeFromString('int64')
                );
            } elseif (Variable::TYPE_NATIVE_BOOL === $value->type) {
                $longVal = $this->context->builder->zExt(
                    $this->context->helper->loadValue($value),
                    $this->context->getTypeFromString('int64')
                );
            }
            if (null !== $longVal) {
                $nativeType = $this->context->getTypeFromString('int64');
                $nativePtr = $this->context->memory->malloc($nativeType);
                $this->context->builder->store($longVal, $nativePtr);
                $this->context->builder->store(
                    $this->context->builder->pointerCast($nativePtr, $voidPtr),
                    $slot
                );

                return;
            }
        }

        if (Variable::TYPE_NATIVE_DOUBLE === $propertyType) {
            $doubleVal = null;
            if (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
                $doubleVal = $this->context->helper->loadValue($value);
            } elseif (Variable::TYPE_VALUE === $value->type) {
                $valuePtr = Variable::KIND_VARIABLE === $value->kind
                    ? JitValueBox::pointer($this->context, $value->value)
                    : $value->value;
                $doubleVal = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readDouble'),
                    $valuePtr
                );
            } elseif (Variable::TYPE_NATIVE_LONG === $value->type) {
                $doubleVal = $this->context->builder->siToFp(
                    $this->context->helper->loadValue($value),
                    $this->context->getTypeFromString('double')
                );
            }
            if (null !== $doubleVal) {
                $nativeType = $this->context->getTypeFromString('double');
                $nativePtr = $this->context->memory->malloc($nativeType);
                $this->context->builder->store($doubleVal, $nativePtr);
                $this->context->builder->store(
                    $this->context->builder->pointerCast($nativePtr, $voidPtr),
                    $slot
                );

                return;
            }
        }

        if (Variable::TYPE_NATIVE_BOOL === $propertyType) {
            $boolVal = null;
            if (Variable::TYPE_NATIVE_BOOL === $value->type) {
                $boolVal = $this->context->helper->loadValue($value);
            } elseif (Variable::TYPE_VALUE === $value->type) {
                $valuePtr = Variable::KIND_VARIABLE === $value->kind
                    ? JitValueBox::pointer($this->context, $value->value)
                    : $value->value;
                $boolVal = $this->context->builder->truncOrBitCast(
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    ),
                    $this->context->getTypeFromString('int1')
                );
            } elseif (Variable::TYPE_NATIVE_LONG === $value->type) {
                $boolVal = $this->context->builder->truncOrBitCast(
                    $this->context->helper->loadValue($value),
                    $this->context->getTypeFromString('int1')
                );
            }
            if (null !== $boolVal) {
                $nativeType = $this->context->getTypeFromString('int1');
                $nativePtr = $this->context->memory->malloc($nativeType);
                $this->context->builder->store($boolVal, $nativePtr);
                $this->context->builder->store(
                    $this->context->builder->pointerCast($nativePtr, $voidPtr),
                    $slot
                );

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
