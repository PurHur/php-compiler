<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\VM\ClassEntry;

/**
 * VM TYPE_DECLARE_INTERFACE / TYPE_DECLARE_TRAIT / TYPE_DECLARE_ENUM /
 * TYPE_DECLARE_CLASS dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner class-like declare
 * case bodies (php-src Zend/zend_compile.c zend_compile_class_decl /
 * zend_early_binding; Zend/zend_vm_def.h ZEND_DECLARE_CLASS /
 * ZEND_DECLARE_FUNCTION; zend_enum.c enum declare). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI. Companion helpers live in
 * {@see ClassInheritDefineAndConstDeclare}.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait DeclareClassLikeDispatch
{
    /**
     * Execute TYPE_DECLARE_INTERFACE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeDeclareInterfaceDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $name = $frame->scope[$op->arg1]->toString();
        $lcname = strtolower($name);
        if (isset($this->context->classes[$lcname])) {
            $this->raiseDuplicateClassLikeDeclareFatal('interface', $name, $frame);
        }
        $ifaceEntry = new VM\ClassEntry($name);
        $ifaceEntry->isInterface = true;
        \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($ifaceEntry);
        $ifaceEntry->interfaces = $op->classImplements;
        $ifaceEntry->attributeNames = $op->attributeNames;
        $ifaceEntry->attributeEntries = $op->attributeEntries;
        $ifaceEntry->classDeprecated = $op->deprecatedMetadata;
        if ($op->isSealed) {
            $ifaceEntry->sealed = true;
            $ifaceEntry->sealedPermits = $this->normalizeSealedPermits($name, $op->sealedPermits);
        }
        if (null !== $op->block1) {
            self::defineClass($ifaceEntry, $op->block1, $frame);
        }
        try {
            $this->inheritFromInterfaces($ifaceEntry);
        } catch (\CompileError $e) {
            // Ambiguous iface constants on `interface K extends I, J` (#26672).
            if (VmEval::EVAL_FILENAME === $frame->scriptPath
                || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
            ) {
                throw $e;
            }
            $this->raiseClassDeclareCompileFatal($e, $frame);
        }
        $this->context->classes[$lcname] = $ifaceEntry;
        $this->propagateInterfaceConstantsToImplementors($lcname);
        $this->flushDeferredTraitUses($frame);
        $this->flushDeferredClassConstants();
        return null;
    }

    /**
     * Execute TYPE_DECLARE_TRAIT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeDeclareTraitDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $name = $frame->scope[$op->arg1]->toString();
        $lcname = strtolower($name);
        if (isset($this->context->classes[$lcname])) {
            $this->raiseDuplicateClassLikeDeclareFatal('trait', $name, $frame);
        }
        $traitEntry = new ClassEntry($name);
        $traitEntry->isTrait = true;
        \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($traitEntry);
        $traitEntry->attributeNames = $op->attributeNames;
        $traitEntry->attributeEntries = $op->attributeEntries;
        $traitEntry->classDeprecated = $op->deprecatedMetadata;
        self::defineClass($traitEntry, $op->block1, $frame);
        $this->context->classes[$lcname] = $traitEntry;
        $this->flushDeferredTraitUses($frame);
        $this->flushDeferredClassConstants();
        return null;
    }

    /**
     * Execute TYPE_DECLARE_ENUM for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeDeclareEnumDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $name = $frame->scope[$op->arg1]->toString();
        $lcname = strtolower($name);
        if (isset($this->context->classes[$lcname]) || isset($this->context->enums[$lcname])) {
            $this->raiseDuplicateClassLikeDeclareFatal('enum', $name, $frame);
        }
        $classEntry = new ClassEntry($name);
        $classEntry->isEnum = true;
        // Enums never grow dynamic properties (zend_enum.c / #26588).
        $classEntry->noDynamicProperties = true;
        $classEntry->allowsDynamicProperties = false;
        \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($classEntry);
        if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
            $classEntry->backedType = $frame->block->constants[$op->arg2]->toString();
        }
        $classEntry->interfaces = $op->classImplements;
        $classEntry->isAbstract = $op->classIsAbstract;
        $classEntry->attributeNames = $op->attributeNames;
        $classEntry->attributeEntries = $op->attributeEntries;
        $classEntry->classDeprecated = $op->deprecatedMetadata;
        $classEntry->sourceLocation = $op->sourceLocation;
        VM\ImplementsHierarchyRuntimeCheck::assertAllowed(
            $name,
            $op->classImplements,
            $this->context,
            $frame,
            $op->sourceLocation,
            null,
            true
        );
        if ([] !== $op->classImplements) {
            $missingIface = VM\ImplementsHierarchyRuntimeCheck::missingInterfaceMessage(
                $op->classImplements,
                $op->classImplementsDisplay,
                $this->context
            );
            if (null !== $missingIface) {
                $catchFrame = $this->dispatchVmError($missingIface, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            $notIface = VM\ImplementsHierarchyRuntimeCheck::notInterfaceMessage(
                $name,
                $op->classImplements,
                $op->classImplementsDisplay,
                $this->context
            );
            if (null !== $notIface) {
                $this->raiseClassDeclareCompileFatal(new \CompileError($notIface), $frame);
            }
        }
        self::defineClass($classEntry, $op->block1, $frame);
        try {
            $this->inheritFromInterfaces($classEntry);
        } catch (\CompileError $e) {
            if (VmEval::EVAL_FILENAME === $frame->scriptPath
                || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
            ) {
                throw $e;
            }
            $this->raiseClassDeclareCompileFatal($e, $frame);
        }
        VM\EnumSupport::ensureBuiltinCasesMethod($classEntry);
        VM\EnumSupport::ensureBuiltinEnumInterfaces($classEntry);
        $this->context->classes[$lcname] = $classEntry;
        $this->context->enums[$lcname] = true;
        $this->flushDeferredTraitUses($frame);
        $this->flushDeferredClassConstants();
        return null;
    }

    /**
     * Execute TYPE_DECLARE_CLASS for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeDeclareClassDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $name = $frame->scope[$op->arg1]->toString();
        $lcname = strtolower($name);
        if (isset($this->context->classes[$lcname])) {
            $this->raiseDuplicateClassLikeDeclareFatal('class', $name, $frame);
        }
        $classEntry = new ClassEntry($name);
        \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($classEntry);
        $classEntry->interfaces = $op->classImplements;
        $parentPending = false;
        if (null !== $op->arg2) {
            $parentName = $frame->scope[$op->arg2]->toString();
            $parentLc = strtolower($parentName);
            if (!isset($this->context->classes[$parentLc])) {
                $this->context->autoloadClass($parentName);
            }
            if (!isset($this->context->classes[$parentLc])) {
                $parentPending = true;
            }
            $classEntry->parentLc = $parentLc;
        }
        if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
            $classFlags = $frame->block->constants[$op->arg3]->toInt();
            $classEntry->readonly = VM\ClassFlags::isReadonly($classFlags);
            $classEntry->isAbstract = VM\ClassFlags::isAbstract($classFlags);
            $classEntry->isStatic = VM\ClassFlags::isStatic($classFlags);
            $classEntry->isFinal = VM\ClassFlags::isFinal($classFlags);
        }
        if ($op->isSealed) {
            $classEntry->sealed = true;
            $classEntry->sealedPermits = $this->normalizeSealedPermits($name, $op->sealedPermits);
        }
        $this->assertAllowedBySealedParents($name, $classEntry->parentLc, $classEntry->interfaces);
        $classEntry->attributeNames = $op->attributeNames;
        $classEntry->isAbstract = $op->classIsAbstract;
        $classEntry->allowsDynamicProperties = AttributeNames::hasAllowDynamicProperties(
            $op->attributeNames
        );
        $classEntry->attributeEntries = $op->attributeEntries;
        $classEntry->classDeprecated = $op->deprecatedMetadata;
        $classEntry->sourceLocation = $op->sourceLocation;
        VM\ImplementsHierarchyRuntimeCheck::assertAllowed(
            $name,
            $op->classImplements,
            $this->context,
            $frame,
            $op->sourceLocation,
            $classEntry->parentLc,
            false
        );
        if ([] !== $op->classImplements) {
            $missingIface = VM\ImplementsHierarchyRuntimeCheck::missingInterfaceMessage(
                $op->classImplements,
                $op->classImplementsDisplay,
                $this->context
            );
            if (null !== $missingIface) {
                $catchFrame = $this->dispatchVmError($missingIface, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            $notIface = VM\ImplementsHierarchyRuntimeCheck::notInterfaceMessage(
                $name,
                $op->classImplements,
                $op->classImplementsDisplay,
                $this->context
            );
            if (null !== $notIface) {
                $this->raiseClassDeclareCompileFatal(new \CompileError($notIface), $frame);
            }
        }
        self::defineClass($classEntry, $op->block1, $frame);
        try {
            if (!$parentPending && null !== $classEntry->parentLc) {
                $this->inheritFromParent($classEntry);
            }
            // Inherited static properties arrive after defineClass(); relink hooks (#6566).
            if (!$parentPending) {
                $this->linkStaticPropertyHooks($classEntry);
            }
            $this->inheritFromInterfaces($classEntry);
            if (VM\LazyGhostTraitSupport::classUsesLazyGhostTrait($classEntry, $this->context)) {
                VM\LazyGhostTraitSupport::ensureBuiltinLazyGhostMethods($classEntry);
            }
            if (!$parentPending) {
                VM\ClassValidator::finalizeClassDefinition($classEntry, $this->context, $frame);
            }
        } catch (\CompileError $e) {
            // Inside eval(): rethrow so TYPE_EVAL can raiseEvalCompileFatal with the
            // caller site (Zend "file(line) : eval()'d code"). Outside eval, print and
            // ScriptExit — cli_driver otherwise exits 255 without a message (#25384).
            if (VmEval::EVAL_FILENAME === $frame->scriptPath
                || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
            ) {
                throw $e;
            }
            $this->raiseClassDeclareCompileFatal($e, $frame);
        }
        $this->context->classes[$lcname] = $classEntry;
        if ($parentPending) {
            $this->context->deferredParentInheritance[] = [
                'childLc' => $lcname,
                'parentName' => $parentName,
            ];
        }
        try {
            $this->flushDeferredParentInheritance($frame);
        } catch (\CompileError $e) {
            $this->raiseClassDeclareCompileFatal($e, $frame);
        }
        $this->flushDeferredTraitUses($frame);
        $this->flushDeferredClassConstants();
        return null;
    }
}
