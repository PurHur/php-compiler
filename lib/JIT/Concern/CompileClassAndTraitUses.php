<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Builtin\AttributeRegistry;
use PHPCompiler\JIT\Variable;
use PHPCompiler\MethodVisibility;

/**
 * Class / trait composition entry (`compileClass`) for JIT/AOT (#36387 / #36403).
 *
 * Trait-use adaptations and deferred abstract-trait body flush live in
 * {@see CompileTraitUsesWithAdaptations}. Hub shrink toward the 20k size-budget target.
 */
trait CompileClassAndTraitUses
{
    private function compileClass(?Block $block, int $classId) {
        if ($block === null) {
            return;
        }
        $ownMethods = [];
        $traitMethodSources = [];
        /** @var list<string> */
        $pendingTraitNames = [];
        /** @var list<array<string, mixed>> */
        $pendingTraitAdaptations = [];
        /** @var list<OpCode> */
        $pendingPropertyNewDefaultOps = [];
        $pendingPropertyNewClassName = null;
        foreach ($block->opCodes as $op) {
            if ([] !== $pendingPropertyNewDefaultOps) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type) {
                    $pendingPropertyNewClassName = $this->jitPropertyNewClassNameFromOps($block, $pendingPropertyNewDefaultOps);
                    $pendingPropertyNewDefaultOps = [];
                } elseif (OpCode::TYPE_DECLARE_STATIC_PROPERTY === $op->type
                    || OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                    $pendingPropertyNewDefaultOps = [];
                    $pendingPropertyNewClassName = null;
                } else {
                    $pendingPropertyNewDefaultOps[] = $op;

                    continue;
                }
            } elseif (OpCode::TYPE_NEW === $op->type) {
                $pendingPropertyNewDefaultOps[] = $op;

                continue;
            }
            if (OpCode::TYPE_TRAIT_USE_ADAPTATION === $op->type) {
                if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                    $pendingTraitNames = [];
                    $pendingTraitAdaptations = [];

                    continue;
                }
                // Keep names + adaptations; compose after all own methods are declared (#36382).
                $pendingTraitAdaptations = $op->traitAdaptations;

                continue;
            }
            // Do not flushPendingJitTraitUses on every non-USE_TRAIT opcode — that composed
            // trait bodies before later DECLARE_METHOD names existed (StreamTrait::__toString
            // calling Stream::isSeekable, #36382). End-of-class flush below is enough.
            switch ($op->type) {
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $className = $this->context->scope->className ?? '';
                    $declaredJitType = Variable::getTypeFromType(
                        $this->opCodeArgSlotType($block, $op, (int) $op->arg3)
                    );
                    if (
                        Variable::TYPE_NATIVE_LONG !== $declaredJitType
                        && Variable::TYPE_STRING !== $declaredJitType
                        && Variable::TYPE_NATIVE_BOOL !== $declaredJitType
                        && Variable::TYPE_NATIVE_DOUBLE !== $declaredJitType
                        && Variable::TYPE_HASHTABLE !== $declaredJitType
                    ) {
                        $declaredJitType = $this->context->type->object->externalPropertyJitType(
                            $className,
                            $name->value
                        );
                    }
                    $default = (null !== $op->arg2 && isset($block->constants[$op->arg2]))
                        ? $block->constants[$op->arg2]
                        : null;
                    $prototype = (null !== $op->arg3 && isset($block->constants[$op->arg3]))
                        ? $block->constants[$op->arg3]
                        : null;
                    $this->context->type->object->defineStaticProperty(
                        $classId,
                        $name->value,
                        $declaredJitType,
                        $default,
                        $prototype,
                        false,
                        \PHPCompiler\MethodVisibility::mask($op->propertyVisibility)
                    );
                    if (null !== $prototype && null !== $prototype->dnfArms) {
                        $this->context->type->object->defineStaticPropertyDnfArms(
                            $classId,
                            $name->value,
                            $prototype->dnfArms
                        );
                    }
                    $this->context->type->object->defineStaticPropertySetVisibility(
                        $classId,
                        $name->value,
                        (int) ($op->propertySetVisibility ?? 0)
                    );
                    $this->context->type->object->defineStaticPropertyGetVisibility(
                        $classId,
                        $name->value,
                        (int) ($op->propertyGetVisibility ?? 0)
                    );
                    if ($op->propertyAsymmetricExplicitRead ?? false) {
                        $this->context->type->object->defineStaticPropertyAsymmetricExplicitRead(
                            $classId,
                            $name->value
                        );
                    }
                    // PHP 8.4 final static (#23403, #23683) — inheritance + Reflection; writes allowed.
                    if ($op->propertyFinal ?? false) {
                        $this->context->type->object->markPropertyFinal($classId, $name->value);
                    }
                    $this->markJitPropertyVirtualFromHookRegistry($className, $classId, $name->value);
                    break;
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $className = $this->context->scope->className ?? '';
                    $declaredJitType = Variable::getTypeFromType(
                        $this->opCodeArgSlotType($block, $op, (int) $op->arg3)
                    );
                    if (Variable::TYPE_HASHTABLE === $declaredJitType || Variable::TYPE_STRING === $declaredJitType) {
                        $jitType = $declaredJitType;
                        $lcClass = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                        if (
                            !str_starts_with($lcClass, 'phpcfg\\')
                            && !str_starts_with($lcClass, 'phpcompiler\\')
                        ) {
                            if (Variable::TYPE_HASHTABLE === $declaredJitType) {
                                $jitType = Variable::TYPE_VALUE;
                            }
                            // User string properties: boxed __value__ slots (fetch/store parity, #4598).
                            if (Variable::TYPE_STRING === $declaredJitType) {
                                $jitType = Variable::TYPE_VALUE;
                            }
                        }
                    } else {
                        $lcClass = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                        if (
                            !str_starts_with($lcClass, 'phpcfg\\')
                            && !str_starts_with($lcClass, 'phpcompiler\\')
                        ) {
                            // User classes: native slots for declared scalars (VALUE-box fetch segfaults MCJIT, #5111).
                            $jitType = $declaredJitType;
                            $propType = $this->opCodeArgSlotType($block, $op, (int) $op->arg3);
                            $userType = is_object($propType) ? ($propType->userType ?? null) : null;
                            if (is_string($userType) && 0 === strcasecmp($userType, 'SplObjectStorage')) {
                                // Boxed object slots: native TYPE_OBJECT property fetch breaks method calls (#8422).
                                $jitType = Variable::TYPE_VALUE;
                            }
                        } else {
                            $jitType = $this->context->type->object->externalPropertyJitType(
                                $className,
                                $name->value
                            );
                        }
                    }
                    $this->context->type->object->defineProperty($classId, $name->value, $jitType);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $proto = $block->constants[$op->arg3];
                        if (null !== $proto->dnfArms) {
                            $this->context->type->object->definePropertyDnfArms(
                                $classId,
                                $name->value,
                                $proto->dnfArms
                            );
                        }
                        if (
                            null !== $proto->classConstraint
                            && '' !== $proto->classConstraint
                            && null === $proto->dnfArms
                        ) {
                            $this->context->type->object->definePropertyClassConstraint(
                                $classId,
                                $name->value,
                                $proto->classConstraint
                            );
                        }
                        if (\PHPCompiler\VM\TypedPropertyCheck::propertyAllowsNull($proto)) {
                            $this->context->type->object->markPropertyAllowsNull($classId, $name->value);
                        }
                        if (\PHPCompiler\VM\TypedPropertyCheck::propertyAllowsArray($proto)) {
                            $this->context->type->object->markPropertyAllowsArray($classId, $name->value);
                        }
                        $typeLabel = \PHPCompiler\VM\TypedPropertyCheck::uninitializedTypeString($proto);
                        if ('' !== $typeLabel) {
                            $this->context->type->object->definePropertyDeclaredTypeLabel(
                                $classId,
                                $name->value,
                                $typeLabel
                            );
                        }
                        // Typed / explicit mixed prototypes stay UNDEFINED; untyped are TYPE_NULL (#22021).
                        if ($proto->isUndefined() || $proto->hasDeclaredTypeConstraint()) {
                            $this->context->type->object->markPropertyTypedInitGuard($classId, $name->value);
                        }
                    }
                    $this->context->type->object->definePropertyVisibility(
                        $classId,
                        $name->value,
                        \PHPCompiler\MethodVisibility::mask($op->propertyVisibility)
                    );
                    $setVis = (int) ($op->propertySetVisibility ?? 0);
                    $setVis = \PHPCompiler\PropertyVisibility::withImplicitReadonlyProtectedSet(
                        $op->propertyReadonly || $this->context->scope->classIsReadonly,
                        \PHPCompiler\MethodVisibility::mask($op->propertyVisibility),
                        $setVis
                    );
                    $this->context->type->object->definePropertySetVisibility(
                        $classId,
                        $name->value,
                        $setVis
                    );
                    $this->context->type->object->definePropertyGetVisibility(
                        $classId,
                        $name->value,
                        (int) ($op->propertyGetVisibility ?? 0)
                    );
                    if ($op->propertyAsymmetricExplicitRead ?? false) {
                        $this->context->type->object->definePropertyAsymmetricExplicitRead(
                            $classId,
                            $name->value
                        );
                    }
                    if ($op->propertyReadonly || $this->context->scope->classIsReadonly) {
                        $this->context->type->object->markPropertyReadonly($classId, $name->value);
                    }
                    if ($op->propertyFinal ?? false) {
                        $this->context->type->object->markPropertyFinal($classId, $name->value);
                    }
                    if ($op->propertyFromConstructorPromotion ?? false) {
                        $this->context->type->object->markPropertyFromConstructorPromotion(
                            $classId,
                            $name->value
                        );
                    }
                    $this->markJitPropertyVirtualFromHookRegistry($className, $classId, $name->value);
                    if (
                        null !== $op->arg2
                        && isset($block->constants[$op->arg2])
                        && !(
                            $op->propertyFromConstructorPromotion
                            && ($op->propertyReadonly || $this->context->scope->classIsReadonly)
                        )
                    ) {
                        $this->context->type->object->definePropertyDefault(
                            $classId,
                            $name->value,
                            $block->constants[$op->arg2]
                        );
                    }
                    if (null !== $pendingPropertyNewClassName) {
                        $this->context->type->object->definePropertyRuntimeNewDefault(
                            $classId,
                            $name->value,
                            $pendingPropertyNewClassName
                        );
                        $pendingPropertyNewClassName = null;
                    }
                    $this->context->type->object->assertClassTraitInstancePropertyMerge(
                        $classId,
                        $name->value
                    );
                    // Subclasses may have DECLARE_CLASS'd before this parent slot existed (#33886).
                    $this->context->type->object->propagateInstancePropertyToSubclasses(
                        $classId,
                        $name->value
                    );
                    if ([] !== $op->attributeNames) {
                        $classLc = '' !== $this->context->scope->className
                            ? strtolower(ltrim($this->context->scope->className, '\\'))
                            : strtolower(ltrim($this->context->type->object->classNameForId($this->context->scope->classId), '\\'));
                        if ('' !== $classLc) {
                            $attrNames = [];
                            foreach ($op->attributeNames as $n) {
                                $attrNames[] = ltrim($n, '\\');
                            }
                            AttributeRegistry::emitRegisterMethod(
                                $this->context,
                                $classLc,
                                strtolower($name->value),
                                $attrNames
                            );
                        }
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                case OpCode::TYPE_CLASS_CONST_FETCH:
                case OpCode::TYPE_INIT_ARRAY:
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                case OpCode::TYPE_ARRAY_SPREAD:
                case OpCode::TYPE_ARG_SEND:
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    // Default property values are initialized in __object__ allocation.
                    // Object class constants are materialized at TYPE_DECLARE_CLASS_CONST (#3196).
                    break;
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                case OpCode::TYPE_BITWISE_NOT:
                case OpCode::TYPE_BOOLEAN_NOT:
                case OpCode::TYPE_CONCAT:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SPACESHIP:
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                case OpCode::TYPE_LOGICAL_XOR:
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_PROPERTY_FETCH:
                case OpCode::TYPE_PROPERTY_FETCH_WRITE:
                    // Scalar class const expressions — evaluated in jitClassConstDefineValue (#5394, #24928).
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $methodLc = strtolower($name->value);
                    $ownMethods[$methodLc] = true;
                    if ([] !== $op->attributeNames) {
                        $classLc = '' !== $this->context->scope->className
                            ? strtolower(ltrim($this->context->scope->className, '\\'))
                            : strtolower(ltrim($this->context->type->object->classNameForId($this->context->scope->classId), '\\'));
                        if ('' !== $classLc) {
                            $attrNames = [];
                            foreach ($op->attributeNames as $n) {
                                $attrNames[] = ltrim($n, '\\');
                            }
                            AttributeRegistry::emitRegisterMethod(
                                $this->context,
                                $classLc,
                                $methodLc,
                                $attrNames
                            );
                            $hookReflection = \PHPCompiler\SourcePreprocessor\PropertyHooks::reflectionNameFromHookMethod($methodLc);
                            if (null !== $hookReflection) {
                                AttributeRegistry::emitRegisterMethod(
                                    $this->context,
                                    $classLc,
                                    $hookReflection,
                                    $attrNames
                                );
                            }
                        }
                    }
                    $visFlags = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $storedFlags = $block->constants[$op->arg3]->toInt();
                        $visFlags = MethodVisibility::mask($storedFlags);
                        if (($storedFlags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                            $visFlags |= \PHPCfg\Func::FLAG_STATIC;
                        }
                        if (($storedFlags & \PHPCfg\Func::FLAG_FINAL) !== 0) {
                            $visFlags |= \PHPCfg\Func::FLAG_FINAL;
                        }
                    }
                    $methodBlock = $op->block1;
                    if (null !== $methodBlock && null !== $methodBlock->func
                        && (($methodBlock->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                        $visFlags |= \PHPCfg\Func::FLAG_STATIC;
                    }
                    $this->context->type->object->defineMethodVisibility(
                        $classId,
                        $methodLc,
                        $visFlags,
                        $name->value
                    );
                    if (($this->isBundledSuperglobalsClass($classId) || $this->shouldSkipExternalClassBodyLowering($classId))
                        && 'issuperglobalname' !== $methodLc
                    ) {
                        break;
                    }
                    $methodBlock = $op->block1;
                    $className = null !== $methodBlock && null !== $methodBlock->func && null !== $methodBlock->func->class
                        ? strtolower($methodBlock->func->class->value)
                        : $this->context->scope->className;
                    $displayClass = $this->context->type->object->classNameForId($this->context->scope->classId);
                    if ('' === $displayClass) {
                        $displayClass = $className;
                    }
                    $funcName = $displayClass.'::'.$methodLc;
                    if (null !== $methodBlock) {
                        if ('__construct' === $methodLc) {
                            $this->context->type->object->markHasConstructor($this->context->scope->classId);
                        }
                        if ('__destruct' === $methodLc) {
                            $this->context->type->object->recordDestructorBlock(
                                $this->context->scope->classId,
                                $methodBlock
                            );
                        }
                        if ($this->context->type->object->isTraitClass($this->context->scope->className ?? '')) {
                            $this->context->type->object->recordTraitMethodBlock(
                                $this->context->scope->classId,
                                $methodLc,
                                $methodBlock
                            );
                            break;
                        }
                        if (!str_starts_with(strtolower(ltrim($displayClass, '\\')), 'phpcompiler\\')) {
                            JIT\Builtin\ReflectionMethodQueryLowering::recordUserMethodFromBlock(
                                $displayClass,
                                $methodLc,
                                $methodBlock
                            );
                        }
                        // SPINE_CHUNK hub capacity: demote Runtime/Block/VM/Builtin* / OpCode /
                        // ModuleAbstract/Frame/Config + AOT/Compiler/Web/Ast/Cli/JIT/ext/Func/Cfg/Lint/Visitor (#36387).
                        if (JIT\SpineChunkRuntimeMethodDemote::shouldDemote((string) $displayClass)) {
                            JIT\SpineChunkRuntimeMethodDemote::demoteMethodBlock($methodBlock, $methodLc);
                        }
                        $this->compileBlock($methodBlock, $funcName);
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $constNameLc = strtolower($name->value);
                    $constValue = $this->jitClassConstDefineValue(
                        $block,
                        $op,
                        $constNameLc,
                        $classId,
                        (string) $name->value
                    );
                    $enumCaseRef = null;
                    if (
                        !($this->context->type->object->isEnumClassId($classId) && $op->isEnumCaseDeclare)
                    ) {
                        $enumCaseRef = $this->tryResolveEnumCaseClassConstInit($block, $op->arg2);
                        if (
                            null === $enumCaseRef
                            && isset($block->constants[$op->arg2])
                            && \PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $block->constants[$op->arg2]->type
                        ) {
                            $enumCaseRef = $this->tryEnumCaseRefFromVmConstant($block->constants[$op->arg2]);
                        }
                    }
                    if (null !== $enumCaseRef) {
                        $this->context->type->object->defineClassConstEnumCaseRef(
                            $classId,
                            $name->value,
                            $enumCaseRef[0],
                            $enumCaseRef[1]
                        );
                        $this->context->type->object->defineClassConstVisibility(
                            $classId,
                            $name->value,
                            $op->classConstVisibilityFlags
                        );
                        $this->context->type->object->defineClassConstDeprecated(
                            $classId,
                            $name->value,
                            $op->deprecatedMetadata
                        );
                        break;
                    }
                    if (!isset($block->constants[$op->arg2])) {
                        if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                            break;
                        }
                        if ($this->context->type->object->isEnumClassId($classId) && $op->isEnumCaseDeclare) {
                            $this->context->type->object->defineEnumCaseConst($classId, $name->value, $constValue);
                            break;
                        }
                        $enumCaseRef = $this->tryResolveEnumCaseClassConstInit($block, $op->arg2);
                        if (null !== $enumCaseRef) {
                            $this->context->type->object->defineClassConstEnumCaseRef(
                                $classId,
                                $name->value,
                                $enumCaseRef[0],
                                $enumCaseRef[1]
                            );
                            break;
                        }
                    $this->context->type->object->defineClassConst(
                        $classId,
                        $name->value,
                        $constValue
                    );
                    $this->context->type->object->defineClassConstVisibility(
                        $classId,
                        $name->value,
                        $op->classConstVisibilityFlags
                    );
                    $this->context->type->object->defineClassConstDeprecated(
                        $classId,
                        $name->value,
                        $op->deprecatedMetadata
                    );
                    if ([] !== $op->attributeNames) {
                        $classLc = '' !== $this->context->scope->className
                            ? strtolower(ltrim($this->context->scope->className, '\\'))
                            : strtolower(ltrim($this->context->type->object->classNameForId($this->context->scope->classId), '\\'));
                        if ('' !== $classLc) {
                            $attrNames = [];
                            foreach ($op->attributeNames as $n) {
                                $attrNames[] = ltrim($n, '\\');
                            }
                            AttributeRegistry::emitRegisterMethod(
                                $this->context,
                                $classLc,
                                \PHPCompiler\ClassConstName::key((string) $name->value),
                                $attrNames
                            );
                        }
                    }
                    break;
                }
                if ($this->context->type->object->isEnumClassId($classId) && $op->isEnumCaseDeclare) {
                        $this->context->type->object->defineEnumCaseConst(
                            $classId,
                            $name->value,
                            $constValue
                        );
                        break;
                    }
                $this->context->type->object->defineClassConst(
                    $classId,
                    $name->value,
                    $constValue
                );
                $this->context->type->object->defineClassConstVisibility(
                    $classId,
                    $name->value,
                    $op->classConstVisibilityFlags
                );
                $this->context->type->object->defineClassConstDeprecated(
                    $classId,
                    $name->value,
                    $op->deprecatedMetadata
                );
                if ([] !== $op->attributeNames) {
                        $classLc = '' !== $this->context->scope->className
                            ? strtolower(ltrim($this->context->scope->className, '\\'))
                            : strtolower(ltrim($this->context->type->object->classNameForId($this->context->scope->classId), '\\'));
                        if ('' !== $classLc) {
                            $attrNames = [];
                            foreach ($op->attributeNames as $n) {
                                $attrNames[] = ltrim($n, '\\');
                            }
                            // Member key must match ReflectionClassConstant::$name casing (#25963).
                            // Lookup uses strcasecmp, so ClassConstName::key (exact) is sufficient.
                            AttributeRegistry::emitRegisterMethod(
                                $this->context,
                                $classLc,
                                \PHPCompiler\ClassConstName::key((string) $name->value),
                                $attrNames
                            );
                        }
                    }
                    break;
                case OpCode::TYPE_USE_TRAIT:
                    if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                        break;
                    }
                    $traitOp = $block->getOperand($op->arg1);
                    assert($traitOp instanceof Operand\Literal);
                    $pendingTraitNames[] = (string) $traitOp->value;
                    break;
                default:
                    if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                        break;
                    }
                    throw new \LogicException(
                        'Other class body types are not jittable for now: '.opcode_type_name($op->type)
                    );
            }
        }
        if ([] !== $pendingTraitNames) {
            $this->applyJitTraitUsesWithAdaptations(
                $block,
                $pendingTraitNames,
                $pendingTraitAdaptations,
                $classId,
                $ownMethods,
                $traitMethodSources
            );
        }
        $this->context->type->object->definePendingUndeclaredInstanceProperties(
            $classId,
            $this->context->scope->className ?? ''
        );
    }
}
