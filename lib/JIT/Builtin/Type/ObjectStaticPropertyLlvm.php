<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable;
use PHPLLVM;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for static property fetch/store/isset/unset (#18740, #9938 tranche 4).
 */
final class ObjectStaticPropertyLlvm
{
    /**
     * Zend zend_std_unset_static_property — Error for every declared static (#23691 / #6648).
     * Undeclared names keep the undeclared-property Error.
     * Catchable inside try; otherwise pending Error + early return (#4029 shape).
     */
    public static function unset(Object_ $object, int $classId, string $name, ?\PHPCompiler\JIT $jit = null): void
    {
        $context = $object->jitContext();
        $classLabel = $object->classNameForId($classId);
        $entry = $object->staticPropertyGlobalEntry($classId, $name);
        $message = null === $entry
            ? 'Access to undeclared static property '.$classLabel.'::$'.$name
            : 'Attempt to unset static property '.$classLabel.'::$'.$name;

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $insert = $context->builder->getInsertBlock();
        if (null === $insert || null !== $insert->getTerminator()) {
            return;
        }
        $fn = $insert->getParent();
        if (!$fn instanceof \PHPLLVM\Value\Function_) {
            $fn = BasicBlockHelper::parentFunction($context);
        }
        $failBlock = $fn->appendBasicBlock('static_prop_unset_error');
        $continueBlock = $fn->appendBasicBlock('static_prop_unset_continue');
        $context->builder->positionAtEnd($insert);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($failBlock);
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
            $stillOpen = $context->builder->getInsertBlock();
            if (null !== $stillOpen && null === $stillOpen->getTerminator()) {
                ErrorRaise::emitRaise($context, $message);
                self::returnAfterPendingError($context, $fn);
            }
        } else {
            ErrorRaise::emitRaise($context, $message);
            self::returnAfterPendingError($context, $fn);
        }

        $context->builder->positionAtEnd($continueBlock);
    }

    private static function returnAfterPendingError(Context $context, \PHPLLVM\Value\Function_ $fn): void
    {
        if (BasicBlockHelper::isVoidLlvmFunctionValue($fn)) {
            $context->builder->returnVoid();

            return;
        }
        $fnType = BasicBlockHelper::llvmFunctionSignatureType($fn);
        if (null !== $fnType) {
            $returnType = $fnType->getReturnType();
            if (\PHPLLVM\Type::KIND_POINTER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constNull());

                return;
            }
            if (\PHPLLVM\Type::KIND_INTEGER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constInt(0, false));

                return;
            }
            $structName = $context->getStringFromType($returnType);
            if ('__value__' === $structName) {
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $context->builder->returnValue($context->builder->load($slot));

                return;
            }
        }
        $context->builder->returnVoid();
    }

    public static function fetch(Object_ $object, int $classId, string $name): Variable
    {
        $entry = $object->staticPropertyGlobalEntry($classId, $name);
        if (null === $entry) {
            $context = $object->jitContext();
            $classLabel = $object->classNameForId($classId);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise(
                $context,
                'Access to undeclared static property '.$classLabel.'::$'.$name
            );
            // Unreachable after pending Error; keep a typed null for IR validity.
            return new Variable(
                $context,
                Variable::TYPE_NULL,
                Variable::KIND_VALUE,
                $context->getTypeFromString('int8')->constInt(0, false)
            );
        }
        $context = $object->jitContext();
        if (!empty($entry['typedWithoutDefault']) && null !== ($entry['initGlobal'] ?? null)) {
            $declName = $object->classNameForId($classId);
            $meta = $object->staticPropertyVisibilityMeta($classId, $name);
            if (null !== $meta) {
                $declName = $meta['declaringClassName'];
            }
            TypedPropertyUninitGuard::emitBeforeStaticRead(
                $context,
                $entry['initGlobal'],
                $declName,
                $name
            );
        }
        $loaded = $context->builder->load($entry['global']);
        if (Variable::TYPE_VALUE === $entry['type']) {
            $var = new Variable(
                $context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $loaded
            );
            $var->staticPropertyGlobal = $entry['global'];
            $var->staticPropertyType = $entry['type'];
            $var->staticPropertyInitGlobal = $entry['initGlobal'] ?? null;
            $var->staticPropertyDnfArms = $object->dnfArmsForStaticProperty($classId, $name);

            return $var;
        }
        $var = new Variable(
            $context,
            $entry['type'],
            Variable::KIND_VALUE,
            $loaded
        );
        $var->staticPropertyGlobal = $entry['global'];
        $var->staticPropertyType = $entry['type'];
        $var->staticPropertyInitGlobal = $entry['initGlobal'] ?? null;
        $var->staticPropertyDnfArms = $object->dnfArmsForStaticProperty($classId, $name);

        return $var;
    }

    /** isset(Class::$prop) without reading uninitialized typed slots (#15112, zend_object_handlers.c). */
    public static function compileIsSet(Object_ $object, int $classId, string $name): Value
    {
        $context = $object->jitContext();
        $key = strtolower($name);
        $i1 = $context->getTypeFromString('int1');
        $false = $i1->constInt(0, false);
        $entry = $object->staticPropertyGlobalEntry($classId, $name);
        if (null === $entry) {
            return $false;
        }
        if (!empty($entry['typedWithoutDefault']) && null !== ($entry['initGlobal'] ?? null)) {
            return $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($entry['initGlobal']),
                $i1->constInt(1, false)
            );
        }
        $loaded = $context->builder->load($entry['global']);
        if (Variable::TYPE_VALUE === $entry['type']) {
            return self::compileValueBoxIsSet($context, $loaded);
        }
        if (Variable::TYPE_STRING === $entry['type']) {
            $null = $context->getTypeFromString('__string__*')->constNull();

            return $context->builder->icmp(Builder::INT_NE, $loaded, $null);
        }

        return $i1->constInt(1, false);
    }

    /** Runtime static property name (`Class::$$name`, issue #4597). */
    public static function fetchDynamic(Object_ $object, int $classId, Variable $nameVar): Variable
    {
        $context = $object->jitContext();
        $globals = $object->staticPropertyGlobalsForClass($classId);
        if ([] === $globals) {
            throw new \LogicException('Dynamic static property fetch requires at least one declared static property');
        }

        if (1 === count($globals)) {
            $propName = array_key_first($globals);
            $runtimeName = JitStringArg::lowerDominating($context, $nameVar, 'dynamic static property name');
            $litLoaded = $context->builder->load($context->constantStringFromString($propName));
            $match = JitStringCompare::identical($context, $runtimeName, $litLoaded);
            $fn = BasicBlockHelper::parentFunction($context);
            $entry = $context->builder->getInsertBlock();
            $ok = $fn->appendBasicBlock('dyn_static_prop_one_ok');
            $fail = $fn->appendBasicBlock('dyn_static_prop_one_fail');
            $context->builder->branchIf($match, $ok, $fail);
            $context->builder->positionAtEnd($fail);
            $classLabel = $object->classNameForId($classId);
            ErrorRaise::ensureLinked($context);
            self::emitUndeclaredStaticPropertyRaise($context, $classLabel, $runtimeName);
            $context->builder->returnVoid();
            $context->builder->positionAtEnd($ok);

            return self::fetch($object, $classId, $propName);
        }

        $runtimeName = JitStringArg::lowerDominating($context, $nameVar, 'dynamic static property name');
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('dyn_static_prop_done');
        $exit = $fn->appendBasicBlock('dyn_static_prop_exit');
        $fallback = $fn->appendBasicBlock('dyn_static_prop_undef');
        $destSlot = JitValueBox::alloc($context);
        $multiGlobal = count($globals) > 1;
        $globalSlot = null;
        if ($multiGlobal) {
            $firstGlobal = reset($globals)['global'];
            $globalSlot = $context->memory->malloc($firstGlobal->getType());
        }
        $checkBlock = $entry;
        $i = 0;
        foreach ($globals as $propName => $entry) {
            $context->builder->positionAtEnd($checkBlock);
            $litLoaded = $context->builder->load($context->constantStringFromString($propName));
            $match = JitStringCompare::identical($context, $runtimeName, $litLoaded);
            $caseBlock = $fn->appendBasicBlock('dyn_static_prop_case_'.$classId.'_'.$i);
            $nextCheck = $i + 1 < count($globals)
                ? $fn->appendBasicBlock('dyn_static_prop_try_'.$classId.'_'.($i + 1))
                : $fallback;
            $context->builder->branchIf($match, $caseBlock, $nextCheck);
            $context->builder->positionAtEnd($caseBlock);
            $fetched = self::fetch($object, $classId, $propName);
            self::boxFetchedIntoValue($object, $destSlot, $fetched, $entry['type']);
            if ($multiGlobal && null !== $globalSlot) {
                $context->builder->store($entry['global'], $globalSlot);
            }
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
            ++$i;
        }
        $context->builder->positionAtEnd($fallback);
        $classLabel = $object->classNameForId($classId);
        ErrorRaise::ensureLinked($context);
        self::emitUndeclaredStaticPropertyRaise($context, $classLabel, $runtimeName);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($done);
        $context->builder->branch($exit);
        $context->builder->positionAtEnd($exit);
        $result = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $destSlot
        );
        if (1 === count($globals)) {
            $onlyEntry = reset($globals);
            $result->staticPropertyGlobal = $onlyEntry['global'];
            $result->staticPropertyType = $onlyEntry['type'];
        } elseif (null !== $globalSlot) {
            $result->staticPropertyGlobal = $context->builder->load($globalSlot);
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

    /** Runtime static property name for unset (`unset(Class::$$name)`, issue #4597). */
    public static function unsetDynamic(
        Object_ $object,
        int $classId,
        Variable $nameVar,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $context = $object->jitContext();
        $globals = $object->staticPropertyGlobalsForClass($classId);
        if ([] === $globals) {
            throw new \LogicException('Dynamic static property unset requires at least one declared static property');
        }

        $runtimeName = JitStringArg::lowerDominating($context, $nameVar, 'dynamic static property name');
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('dyn_static_prop_unset_done');
        $fallback = $fn->appendBasicBlock('dyn_static_prop_unset_undef');
        $checkBlock = $entry;
        $i = 0;
        foreach ($globals as $propName => $_entry) {
            $context->builder->positionAtEnd($checkBlock);
            $litLoaded = $context->builder->load($context->constantStringFromString($propName));
            $match = JitStringCompare::identical($context, $runtimeName, $litLoaded);
            $caseBlock = $fn->appendBasicBlock('dyn_static_prop_unset_case_'.$classId.'_'.$i);
            $nextCheck = $i + 1 < count($globals)
                ? $fn->appendBasicBlock('dyn_static_prop_unset_try_'.$classId.'_'.($i + 1))
                : $fallback;
            $context->builder->branchIf($match, $caseBlock, $nextCheck);
            $context->builder->positionAtEnd($caseBlock);
            self::unset($object, $classId, $propName, $jit);
            $after = $context->builder->getInsertBlock();
            if (null !== $after && null === $after->getTerminator()) {
                $context->builder->branch($done);
            }
            $checkBlock = $nextCheck;
            ++$i;
        }
        $context->builder->positionAtEnd($fallback);
        $classLabel = $object->classNameForId($classId);
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        ErrorRaise::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        self::emitUndeclaredStaticPropertyRaise($context, $classLabel, $runtimeName);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($done);
    }

    /**
     * Build `Access to undeclared static property Class::$name` with a runtime name (#23606).
     */
    private static function emitUndeclaredStaticPropertyRaise(
        Context $context,
        string $classLabel,
        Value $runtimeNameStr
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
        $buf = $context->builder->alloca($i8->arrayType(512), 1, 'undecl_static_prop_msg');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $nameMap = $context->structFieldMap['__string__'];
        $nameData = $context->builder->pointerCast(
            $context->builder->structGep($runtimeNameStr, $nameMap['value']),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $context->constantFromInteger(512, 'size_t'),
            $context->builder->pointerCast(
                $context->constantFromString('Access to undeclared static property '.$classLabel.'::$%s'),
                $i8p
            ),
            $nameData
        );
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $bufPtr
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_error'),
            $bufPtr,
            $len
        );
    }

    public static function store(
        Object_ $object,
        Value $global,
        Variable $value,
        int $propertyType,
        ?Value $initGlobal = null
    ): void {
        $context = $object->jitContext();
        if (Variable::TYPE_VALUE === $propertyType) {
            self::storeValueBox($object, $global, $value);
            self::markInitialized($context, $initGlobal);

            return;
        }
        if (Variable::TYPE_STRING === $propertyType) {
            if (Variable::TYPE_VALUE === $value->type) {
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    JitValueBox::valuePtrFromVariable($context, $value)
                );
            } else {
                $stored = $context->helper->loadValue($value);
            }
            $context->builder->store($stored, $global);
            if (Variable::TYPE_STRING === $value->type) {
                $value->addref();
            }
            self::markInitialized($context, $initGlobal);

            return;
        }
        if (Variable::TYPE_HASHTABLE === $propertyType) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'static_prop_ht_store');
            if (Variable::TYPE_VALUE === $value->type) {
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readHashtable'),
                    JitValueBox::valuePtrFromVariable($context, $value)
                );
            } else {
                $stored = $context->helper->loadValue($value);
            }
            $storedTy = $context->getStringFromType($stored->typeOf());
            // NestedJIT may lower array temps as i64 pointers (#20664).
            if ('int64' === $storedTy || 'long long' === $storedTy) {
                $stored = JitNestedHelperCoerce::i64ToTypedPtr(
                    $context,
                    $stored,
                    $context->getTypeFromString('__hashtable__*')
                );
            }
            $context->builder->store($stored, $global);
            self::markInitialized($context, $initGlobal);

            return;
        }
        if (Variable::TYPE_VALUE === $value->type) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'static_prop_scalar_from_box');
            $loaded = $context->builder->call(
                $context->lookupFunction(
                    Variable::TYPE_NATIVE_DOUBLE === $propertyType
                        ? '__value__readDouble'
                        : '__value__readLong'
                ),
                JitValueBox::valuePtrFromVariable($context, $value)
            );
            if (Variable::TYPE_NATIVE_BOOL === $propertyType) {
                $loaded = $context->builder->truncOrBitCast(
                    $loaded,
                    $context->getTypeFromString('int1')
                );
            }
            $context->builder->store($loaded, $global);
            self::markInitialized($context, $initGlobal);

            return;
        }
        $context->builder->store($context->helper->loadValue($value), $global);
        self::markInitialized($context, $initGlobal);
    }

    private static function compileValueBoxIsSet(Context $context, Value $valuePtr): Value
    {
        $nullType = $context->getTypeFromString('int8')->constInt(0, false);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $context->structFieldMap['__value__']['type'])
        );

        return $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
    }

    private static function markInitialized(Context $context, ?Value $initGlobal): void
    {
        if (null === $initGlobal) {
            return;
        }
        $context->builder->store(
            $context->getTypeFromString('int1')->constInt(1, false),
            $initGlobal
        );
    }

    private static function storeValueBox(Object_ $object, Value $global, Variable $value): void
    {
        $context = $object->jitContext();
        $valueType = $context->getTypeFromString('__value__');
        $valuePtrTy = $context->getTypeFromString('__value__*');

        if (Variable::TYPE_VALUE === $value->type) {
            $ptr = Variable::KIND_VARIABLE === $value->kind
                ? JitValueBox::pointer($context, $value->value)
                : $value->value;
            $context->builder->store($ptr, $global);
            $value->addref();

            return;
        }

        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast($heapVal, $valuePtrTy);
        $valueMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $context->builder->structGep($heapVal, $valueMap['type'])
        );

        if (Variable::TYPE_STRING === $value->type) {
            $str = $context->helper->loadValue($value);
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $heapPtr,
                $owned
            );
            $value->addref();
        } elseif (Variable::TYPE_OBJECT === $value->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $heapPtr,
                $context->helper->loadValue($value)
            );
            $value->addref();
        } elseif (Variable::TYPE_HASHTABLE === $value->type) {
            // Boxed static ?array (e.g. UnicodeCanonical::$composeMapCache) — peer typed
            // TYPE_HASHTABLE store above + NestedJIT i64 array temps (#20664 / #23580).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'static_prop_box_ht_store');
            $stored = $context->helper->loadValue($value);
            $storedTy = $context->getStringFromType($stored->typeOf());
            if ('int64' === $storedTy || 'long long' === $storedTy) {
                $stored = JitNestedHelperCoerce::i64ToTypedPtr(
                    $context,
                    $stored,
                    $context->getTypeFromString('__hashtable__*')
                );
            }
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $heapPtr,
                $stored
            );
            $value->addref();
        } elseif (Variable::TYPE_NATIVE_LONG === $value->type || Variable::TYPE_NATIVE_BOOL === $value->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $heapPtr,
                $context->helper->loadValue($value)
            );
        } elseif (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $heapPtr,
                $context->helper->loadValue($value)
            );
        } else {
            throw new \LogicException(
                'JIT static property boxed store does not support value type '
                .Variable::getStringType($value->type)
            );
        }

        $context->builder->store($heapPtr, $global);
    }

    private static function boxFetchedIntoValue(
        Object_ $object,
        Value $destSlot,
        Variable $fetched,
        int $propertyType
    ): void {
        $context = $object->jitContext();
        $destPtr = JitValueBox::pointer($context, $destSlot);
        if (Variable::TYPE_VALUE === $propertyType) {
            JitValueBox::copyFromPointer(
                $context,
                $destSlot,
                JitValueBox::pointer($context, $fetched->value)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $destPtr,
                $context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $propertyType) {
            JitValueBox::writeBool(
                $context,
                $destSlot,
                $context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $destPtr,
                $context->builder->load($fetched->value)
            );

            return;
        }
        if (Variable::TYPE_STRING === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $destPtr,
                $fetched->value
            );

            return;
        }
        if (Variable::TYPE_HASHTABLE === $propertyType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $fetched->value
            );

            return;
        }

        throw new \LogicException(
            'Dynamic static property fetch JIT box unsupported type: '.Variable::getStringType($propertyType)
        );
    }
}
