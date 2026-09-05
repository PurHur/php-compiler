<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\JIT\Builtin\AttributeRegistry;

/**
 * Class-like declare opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_DECLARE_INTERFACE},
 * {@code TYPE_DECLARE_TRAIT}, {@code TYPE_DECLARE_ENUM}, and
 * {@code TYPE_DECLARE_CLASS}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_compile.c (zend_compile_class_decl), Zend/zend_inheritance.c,
 * Zend/zend_API.c (zend_register_internal_class*) — move-only Concern extract; no new C ABI.
 */
trait CompileDeclareClassLike
{
    private function compileDeclareClassLikeOp(Block $block, OpCode $op): void
    {
        switch ($op->type) {
                case OpCode::TYPE_DECLARE_INTERFACE:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'interface', $nameOp->value)) {
                        break;
                    }
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->type->object->setClassSourceLocation(
                        $this->context->scope->classId,
                        $op->sourceLocation
                    );
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->context->type->object->markInterfaceClass($nameOp->value);
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setInterfaceExtends(
                            $nameOp->value,
                            $op->classImplements
                        );
                    }
                    if (null !== $op->block1) {
                        $this->compileClass($op->block1, $this->context->scope->classId);
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->inheritInterfacePropertySetVisibility(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->propagateInterfaceConstantsToImplementors($nameOp->value);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'trait', $nameOp->value)) {
                        break;
                    }
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->type->object->setClassSourceLocation(
                        $this->context->scope->classId,
                        $op->sourceLocation
                    );
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->context->type->object->markTraitClass($this->context->scope->className);
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    if (null !== $this->context->runtime->vmContext) {
                        $lcname = strtolower($nameOp->value);
                        if (!isset($this->context->runtime->vmContext->classes[$lcname])) {
                            $traitEntry = new \PHPCompiler\VM\ClassEntry($nameOp->value);
                            $traitEntry->isTrait = true;
                            $this->context->runtime->vmContext->classes[$lcname] = $traitEntry;
                        }
                    }
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_ENUM:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->context->type->object->isRegisteredEnumLc(strtolower($nameOp->value))) {
                        break;
                    }
                    $this->jitCompileDeclareEnum($block, $op);
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'class', $nameOp->value)) {
                        break;
                    }
                    // php-cfg may emit DECLARE_CLASS before DECLARE_ENUM in the same
                    // script block; compile enums first so class-const `E::X` can
                    // attach the singleton. Must run before pushScope so Enum::from
                    // lowering does not leak into the enclosing class function (#31967).
                    $this->jitCompilePendingEnumsInBlock($block);
                    $declareParentLc = null;
                    if (null !== $op->arg2) {
                        $earlyParent = $block->getOperand($op->arg2);
                        if ($earlyParent instanceof Operand\Literal && is_string($earlyParent->value)) {
                            $declareParentLc = strtolower(ltrim($earlyParent->value, '\\'));
                        }
                    }
                    if ([] !== $op->classImplements) {
                        \PHPCompiler\JIT\ImplementsHierarchyJitGuard::emitBeforeDeclare(
                            $this->context,
                            $nameOp->value,
                            $op->classImplements,
                            $block->scriptPath(),
                            $op->sourceLocation,
                            $declareParentLc,
                            false
                        );
                    }
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->type->object->setClassSourceLocation(
                        $this->context->scope->classId,
                        $op->sourceLocation
                    );
                    $this->context->scope->className = strtolower($nameOp->value);
                    if ($op->classIsAbstract) {
                        $this->context->type->object->markAbstractClass($nameOp->value);
                    }
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $packedFlags = $block->constants[$op->arg3]->toInt();
                        $this->context->scope->classIsReadonly = \PHPCompiler\VM\ClassFlags::isReadonly($packedFlags);
                        $this->context->type->object->setClassReadonly(
                            $this->context->scope->classId,
                            $this->context->scope->classIsReadonly
                        );
                        // Thin AOT isFinal name table (#34043) — ZEND_ACC_FINAL from packed flags.
                        if (\PHPCompiler\VM\ClassFlags::isFinal($packedFlags)) {
                            $this->context->type->object->markFinalClass($nameOp->value);
                        }
                    } else {
                        $this->context->scope->classIsReadonly = false;
                    }
                    $parentOp = null;
                    if (null !== $op->arg2) {
                        $parentOp = $block->getOperand($op->arg2);
                        assert($parentOp instanceof Operand\Literal);
                        $this->context->type->object->setClassParentName($nameOp->value, $parentOp->value);
                        // Before compileClass: user subclass methods / nested `new` need parent
                        // thin-AOT slots (`__spl_ht`, …) already on the child (#27565).
                        $this->context->type->object->inheritParentInstanceProperties(
                            $this->context->scope->classId,
                            strtolower(ltrim($parentOp->value, '\\'))
                        );
                    }
                    if ([] !== $op->attributeNames || [] !== $op->attributeEntries) {
                        $attrNames = [];
                        foreach ($op->attributeNames as $n) {
                            $attrNames[] = ltrim($n, '\\');
                        }
                        if (AttributeNames::hasAllowDynamicProperties($attrNames)) {
                            $this->context->type->object->setClassAllowsDynamicProperties(
                                $this->context->scope->classId,
                                true
                            );
                        }
                        AttributeRegistry::emitRegisterClass(
                            $this->context,
                            strtolower(ltrim($nameOp->value, '\\')),
                            [] !== $op->attributeEntries ? $op->attributeEntries : $attrNames
                        );
                    }
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    // Zend evaluates class-const expressions after implements are attached
                    // (zend_inheritance.c). AOT const rematerialization needs the same order
                    // so `const Y = self::X` can see interface X (#31967).
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setClassInterfaces(
                            $nameOp->value,
                            $op->classImplements
                        );
                        $this->seedVmClassEntryInterfaces($nameOp->value, $op->classImplements);
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    if ($parentOp instanceof Operand\Literal) {
                        $this->context->type->object->inheritReadonlyFromParent(
                            $this->context->scope->classId,
                            $parentOp->value
                        );
                        $this->context->type->object->inheritMethodVisibilityFromParent(
                            $this->context->scope->classId,
                            $this->context->scope->className
                        );
                        $this->context->type->object->inheritParentStaticProperties(
                            $this->context->scope->classId,
                            strtolower(ltrim($parentOp->value, '\\'))
                        );
                    }
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setClassInterfaces(
                            $nameOp->value,
                            $op->classImplements
                        );
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->inheritInterfacePropertySetVisibility(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    // Concrete subclass of AbstractLogger etc.: lower deferred LoggerTrait bodies (#36382).
                    if (!$op->classIsAbstract) {
                        $this->flushDeferredAbstractTraitMethodBodiesForConcrete($nameOp->value);
                    }
                    $this->context->popScope();
                    break;
        }
    }
}
