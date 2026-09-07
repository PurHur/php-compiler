<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * Inheritance final/variance rejects + class-const fetch resolve for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code classConstValuesIdentical} through
 * {@code resolveInheritedClassConstantHolding} (php-src Zend/zend_inheritance.c final
 * method/property/const override + property type invariance; Zend/zend_execute.c /
 * zend_vm_def.h FETCH_CLASS_CONSTANT with static-property fallback — #4455, #22329,
 * #24884, #25384, #22988, #23505, #7012, #3788, #25910). Companion to
 * {@see ClassInheritDefineAndConstDeclare} / {@see ClassTraitComposition}.
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers resolve.
 * Move-only; no new C ABI.
 */
trait InheritanceFinalVarianceAndConstFetch
{
    private function classConstValuesIdentical(Variable $left, Variable $right): bool
    {
        $a = new Variable();
        $a->copyFrom($left);
        $b = new Variable();
        $b->copyFrom($right);

        return $a->identicalTo($b);
    }

    /**
     * Child class/interface body must not redeclare a final ancestor constant
     * (Zend/zend_inheritance.c; #4455 compile-time, #22329 eval/runtime).
     */
    private function rejectChildOverrideOfFinalClassConst(
        ClassEntry $entry,
        ClassEntry $ancestor,
        string $nameLc
    ): void {
        if (!isset($ancestor->constFinal[$nameLc])) {
            return;
        }
        $childDisplay = $entry->constNames[$nameLc]
            ?? $ancestor->constNames[$nameLc]
            ?? $nameLc;
        $constDisplay = $ancestor->constNames[$nameLc] ?? $childDisplay;
        $declaringLc = $ancestor->constDeclaringClassLc[$nameLc]
            ?? strtolower(ltrim($ancestor->name, '\\'));
        $ownerDisplay = $ancestor->name;
        if (isset($this->context->classes[$declaringLc])) {
            $ownerDisplay = $this->context->classes[$declaringLc]->name;
        }
        throw new \CompileError(sprintf(
            '%s::%s cannot override final constant %s::%s',
            $entry->name,
            $childDisplay,
            $ownerDisplay,
            $constDisplay
        ));
    }

    /**
     * php-src zend_inheritance.c — "Cannot override final method %s::%s()" (#24884, #4263).
     * Same-script compile is FinalMethodOverrideCheck; cross-eval/include hits this path.
     */
    private function rejectChildOverrideOfFinalMethod(
        ClassEntry $entry,
        ClassEntry $parent,
        string $methodLc
    ): void {
        $vis = $parent->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (($vis & \PHPCfg\Func::FLAG_FINAL) === 0) {
            return;
        }
        $methodDisplay = $entry->methodNames[$methodLc]
            ?? $parent->methodNames[$methodLc]
            ?? $methodLc;
        $declaringLc = $parent->methodDeclaringClassLc[$methodLc]
            ?? strtolower(ltrim($parent->name, '\\'));
        $ownerDisplay = $parent->name;
        if (isset($this->context->classes[$declaringLc])) {
            $ownerDisplay = $this->context->classes[$declaringLc]->name;
        }
        throw new \CompileError(sprintf(
            'Cannot override final method %s::%s()',
            $ownerDisplay,
            $methodDisplay
        ));
    }

    /**
     * php-src zend_inheritance.c method compatibility — cross-file / eval / include (#25384).
     * Same-script compile is {@see Compiler\InheritanceVariance}; this path sees the live ClassEntry.
     */
    private function rejectIncompatibleChildMethodSignature(
        ClassEntry $entry,
        ClassEntry $parent,
        string $methodLc
    ): void {
        $childSig = Compiler\MethodSig::fromClassEntry($entry, $methodLc);
        $parentSig = $this->resolveAncestorMethodSig($parent, $methodLc);
        if (null === $childSig || null === $parentSig) {
            return;
        }
        $msg = Compiler\InheritanceVariance::methodCompatibilityError(
            $entry->name,
            $methodLc,
            $childSig,
            $parent->name,
            $parentSig,
            fn (string $subtype, string $supertype): bool => $this->isClassSubtypeOfDuringDeclare(
                $subtype,
                $supertype,
                $entry
            ),
            fn (string $classLc, string $interfaceLc): bool => $this->classEntryImplementsInterfaceDuringDeclare(
                $classLc,
                $interfaceLc,
                $entry
            )
        );
        if (null !== $msg) {
            throw new \CompileError($msg);
        }
    }

    /**
     * Walk parent/interface chain for a MethodSig (abstract methods keep types on the declarer).
     */
    private function resolveAncestorMethodSig(ClassEntry $from, string $methodLc): ?Compiler\MethodSig
    {
        $current = $from;
        $guard = 0;
        while ($guard++ < 256) {
            $sig = Compiler\MethodSig::fromClassEntry($current, $methodLc);
            if (null !== $sig) {
                return $sig;
            }
            $declLc = $current->methodDeclaringClassLc[$methodLc] ?? null;
            if (null !== $declLc && isset($this->context->classes[$declLc])) {
                $decl = $this->context->classes[$declLc];
                if ($decl !== $current) {
                    $sig = Compiler\MethodSig::fromClassEntry($decl, $methodLc);
                    if (null !== $sig) {
                        return $sig;
                    }
                }
            }
            if (null === $current->parentLc || !isset($this->context->classes[$current->parentLc])) {
                break;
            }
            $current = $this->context->classes[$current->parentLc];
        }

        return null;
    }

    private function classEntryImplementsInterface(string $classLc, string $interfaceLc): bool
    {
        if ($classLc === $interfaceLc) {
            return true;
        }
        if (!isset($this->context->classes[$classLc])) {
            return false;
        }
        $entry = $this->context->classes[$classLc];
        foreach ($entry->interfaces as $ifaceLc) {
            if ($this->interfaceExtendsOrEquals($ifaceLc, $interfaceLc)) {
                return true;
            }
        }
        if (null !== $entry->parentLc) {
            return $this->classEntryImplementsInterface($entry->parentLc, $interfaceLc);
        }

        return false;
    }

    /**
     * Like {@see isSubclassOf()} but the class under TYPE_DECLARE_CLASS is not in
     * context.classes until after inheritFromParent (#25384 self/static covariance).
     */
    private function isClassSubtypeOfDuringDeclare(
        string $subtypeLc,
        string $supertypeLc,
        ClassEntry $defining
    ): bool {
        if ($subtypeLc === $supertypeLc) {
            return true;
        }
        $definingLc = strtolower(ltrim($defining->name, '\\'));
        if ($subtypeLc === $definingLc) {
            if ($defining->parentLc === $supertypeLc) {
                return true;
            }
            if (null !== $defining->parentLc) {
                return $this->isSubclassOf($defining->parentLc, $supertypeLc)
                    || $defining->parentLc === $supertypeLc;
            }

            return false;
        }

        return $this->isSubclassOf($subtypeLc, $supertypeLc);
    }

    private function classEntryImplementsInterfaceDuringDeclare(
        string $classLc,
        string $interfaceLc,
        ClassEntry $defining
    ): bool {
        if ($classLc === $interfaceLc) {
            return true;
        }
        $definingLc = strtolower(ltrim($defining->name, '\\'));
        if ($classLc === $definingLc) {
            foreach ($defining->interfaces as $ifaceLc) {
                if ($this->interfaceExtendsOrEquals($ifaceLc, $interfaceLc)) {
                    return true;
                }
            }
            if (null !== $defining->parentLc) {
                return $this->classEntryImplementsInterface($defining->parentLc, $interfaceLc);
            }

            return false;
        }

        return $this->classEntryImplementsInterface($classLc, $interfaceLc);
    }

    private function interfaceExtendsOrEquals(string $ifaceLc, string $targetLc): bool
    {
        if ($ifaceLc === $targetLc) {
            return true;
        }
        if (!isset($this->context->classes[$ifaceLc])) {
            return false;
        }
        $iface = $this->context->classes[$ifaceLc];
        foreach ($iface->interfaces as $parentIface) {
            if ($this->interfaceExtendsOrEquals($parentIface, $targetLc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-src zend_inheritance.c — "Cannot override final property %s::$%s" (#22988, #22339).
     */
    private function rejectChildOverrideOfFinalProperty(
        ClassEntry $entry,
        VM\ClassProperty $parentProperty
    ): void {
        if (!$parentProperty->propertyFinal) {
            return;
        }
        $declaringLc = $parentProperty->declaringClassLc;
        $ownerDisplay = $declaringLc !== '' ? $declaringLc : $entry->parentLc;
        if (is_string($ownerDisplay) && isset($this->context->classes[$ownerDisplay])) {
            $ownerDisplay = $this->context->classes[$ownerDisplay]->name;
        }
        if (!is_string($ownerDisplay) || '' === $ownerDisplay) {
            $ownerDisplay = 'parent';
        }
        throw new \CompileError(sprintf(
            'Cannot override final property %s::$%s',
            $ownerDisplay,
            $parentProperty->name
        ));
    }

    /**
     * php-src zend_inheritance.c — final static property override (#24992, #23403).
     * Mirror of {@see rejectChildOverrideOfFinalProperty()} for ClassEntry::$staticPropertyFinal.
     */
    private function rejectChildOverrideOfFinalStaticProperty(
        ClassEntry $entry,
        ClassEntry $parent,
        string $propLc
    ): void {
        $declaringLc = $parent->staticPropertyDeclaringClassLc[$propLc]
            ?? strtolower(ltrim($parent->name, '\\'));
        $ownerDisplay = $declaringLc;
        if (isset($this->context->classes[$ownerDisplay])) {
            $ownerDisplay = $this->context->classes[$ownerDisplay]->name;
        }
        if ('' === $ownerDisplay) {
            $ownerDisplay = $parent->name !== '' ? $parent->name : 'parent';
        }
        $storage = $parent->staticProperties[$propLc] ?? null;
        $propDisplay = ($storage instanceof Variable && null !== $storage->objectPropertyName)
            ? $storage->objectPropertyName
            : $propLc;
        throw new \CompileError(sprintf(
            'Cannot override final property %s::$%s',
            $ownerDisplay,
            $propDisplay
        ));
    }

    /**
     * php-src zend_inheritance.c — property type invariance (#23505).
     * Same-script compile is covered by TypedPropertyInheritCheck; cross-eval needs this path.
     */
    private function rejectIncompatibleChildPropertyType(
        ClassEntry $entry,
        VM\ClassProperty $parentProperty,
        VM\ClassProperty $childProperty
    ): void {
        $parentOwnerLc = $parentProperty->declaringClassLc !== ''
            ? $parentProperty->declaringClassLc
            : (string) ($entry->parentLc ?? '');
        $childOwnerLc = $childProperty->declaringClassLc !== ''
            ? $childProperty->declaringClassLc
            : strtolower(ltrim($entry->name, '\\'));
        if ($this->propertyTypesAreInvariant($parentProperty, $childProperty, $parentOwnerLc, $childOwnerLc)) {
            return;
        }
        $ownerDisplay = $parentOwnerLc;
        if (isset($this->context->classes[$ownerDisplay])) {
            $ownerDisplay = $this->context->classes[$ownerDisplay]->name;
        }
        if ('' === $ownerDisplay) {
            $ownerDisplay = 'parent';
        }
        if (!$parentProperty->hasDeclaredType() && $childProperty->hasDeclaredType()) {
            throw new \CompileError(sprintf(
                'Type of %s::$%s must not be defined (as in class %s)',
                $entry->name,
                $childProperty->name,
                $ownerDisplay
            ));
        }
        throw new \CompileError(sprintf(
            'Type of %s::$%s must be %s (as in class %s)',
            $entry->name,
            $childProperty->name,
            $this->formatPropertyTypeForInheritError($parentProperty),
            $ownerDisplay
        ));
    }

    private function propertyTypesAreInvariant(
        VM\ClassProperty $parent,
        VM\ClassProperty $child,
        string $parentOwnerLc,
        string $childOwnerLc
    ): bool {
        $parentTyped = $parent->hasDeclaredType();
        $childTyped = $child->hasDeclaredType();
        if (!$parentTyped && !$childTyped) {
            return true;
        }
        if (!$parentTyped || !$childTyped) {
            return false;
        }
        $parentKey = $this->propertyTypeInvariantKey($parent, $parentOwnerLc);
        $childKey = $this->propertyTypeInvariantKey($child, $childOwnerLc);
        if ($parentKey === $childKey) {
            return true;
        }

        return $this->propertyTypeResolvedKey($parent, $parentOwnerLc)
            === $this->propertyTypeResolvedKey($child, $childOwnerLc);
    }

    private function propertyTypeInvariantKey(VM\ClassProperty $property, string $ownerLc): string
    {
        $proto = $property->prototype;
        $label = strtolower((string) ($proto->declaredTypeLabel ?? ''));
        if ('' !== $label) {
            return $label;
        }
        $class = strtolower((string) ($proto->classConstraint ?? ''));
        if ('' !== $class) {
            return $class;
        }
        if (null !== $proto->typeConstraint) {
            return 'tc:'.(string) $proto->typeConstraint;
        }
        // Explicit mixed: typed UNDEFINED without label/constraint.
        if (Variable::TYPE_UNDEFINED === $proto->type) {
            return 'mixed';
        }

        return 'typed';
    }

    private function propertyTypeResolvedKey(VM\ClassProperty $property, string $ownerLc): string
    {
        $key = $this->propertyTypeInvariantKey($property, $ownerLc);
        if ('self' === $key || 'static' === $key) {
            return strtolower($ownerLc);
        }

        return $key;
    }

    private function formatPropertyTypeForInheritError(VM\ClassProperty $property): string
    {
        $proto = $property->prototype;
        $label = (string) ($proto->declaredTypeLabel ?? '');
        if ('' !== $label) {
            return $label;
        }
        $class = (string) ($proto->classConstraint ?? '');
        if ('' !== $class) {
            return $class;
        }
        if (Variable::TYPE_UNDEFINED === $proto->type) {
            return 'mixed';
        }
        if (null !== $proto->typeConstraint) {
            return TypeCheck::typeNameForConstraint((int) $proto->typeConstraint);
        }

        return '';
    }

    /**
     * Class body constant after trait use must not redefine an inherited trait constant
     * with an incompatible value (Zend/zend_traits.c zend_traits_compile_role_constants, #7012).
     */
    private function rejectIncompatibleTraitClassConstOverride(
        ClassEntry $entry,
        string $nameLc,
        string $constDisplay,
        Variable $value
    ): void {
        if (!isset($entry->traitConstSources[$nameLc], $entry->constants[$nameLc])) {
            return;
        }
        if ($this->classConstValuesIdentical($entry->constants[$nameLc], $value)) {
            return;
        }
        throw new \LogicException(sprintf(
            '%s and %s define the same constant (%s) in the composition of %s. '
            .'However, the definition differs and is considered incompatible. Class was composed',
            $entry->name,
            $entry->traitConstSources[$nameLc],
            $constDisplay,
            $entry->name
        ));
    }

    /**
     * ClassConstFetch with a runtime member name (php-parser: Class::{$var}).
     * Zend resolves constants first; when no constant exists, fall back to static property (#3788).
     */
    private function copyClassConstOrStaticPropertyByName(
        ClassEntry $classEntry,
        string $memberNameRaw,
        Variable $dest,
        Frame $frame
    ): bool {
        $memberLc = strtolower($memberNameRaw);
        if ('class' === $memberLc) {
            $dest->string($classEntry->name);

            return true;
        }
        // Case-sensitive constant / enum-case key (#25910, #25929).
        $memberKey = ClassConstName::key($memberNameRaw);
        if (
            !isset($classEntry->constants[$memberKey])
            && null !== $classEntry->forwardDeclaredConstNames
            && isset($classEntry->forwardDeclaredConstNames[$memberKey])
        ) {
            // Outer FETCH_CLASS_CONSTANT does not mark visited (zend_vm_def.h); nested
            // sibling fetches do via materializePendingClassConstant(mark=true) (#31837).
            $this->materializePendingClassConstant($classEntry, $memberKey, false, $classEntry->name);
        }
        if (isset($classEntry->constants[$memberKey])) {
            if (!ClassConstName::matchesDeclared(
                $memberNameRaw,
                $this->declaredClassConstName($classEntry, $memberKey)
            )) {
                return false;
            }
            $this->emitClassConstFetchDeprecation($classEntry, $memberNameRaw, $memberKey, $frame);
            if ($classEntry->isEnum && null !== $classEntry->backedType) {
                VM\EnumSupport::ensureBackedEnumValuesUnique($classEntry);
            }
            if (EnumCaseSupport::fetchCaseByMemberName($classEntry, $memberKey, $dest, $this->context)) {
                return true;
            }
            $dest->copyFrom(
                EnumCaseSupport::materializeConstantValue($this->context, $classEntry->constants[$memberKey])
            );

            return true;
        }
        $holding = $this->resolveInheritedClassConstantHolding($classEntry, $memberKey);
        if (null !== $holding) {
            if (!ClassConstName::matchesDeclared(
                $memberNameRaw,
                $this->declaredClassConstName($holding, $memberKey)
            )) {
                return false;
            }
            $inheritedConst = $holding->constants[$memberKey];
            $this->emitClassConstFetchDeprecation($classEntry, $memberNameRaw, $memberKey, $frame);
            if ($classEntry->isEnum && null !== $classEntry->backedType) {
                VM\EnumSupport::ensureBackedEnumValuesUnique($classEntry);
            }
            $dest->copyFrom(EnumCaseSupport::materializeConstantValue($this->context, $inheritedConst));

            return true;
        }
        if (isset($classEntry->staticProperties[$memberLc])) {
            $dest->indirect($classEntry->staticProperties[$memberLc]);

            return true;
        }

        return false;
    }

    /** Declared casing for a class constant / enum case (#25910, #5385, #25929). */
    private function declaredClassConstName(ClassEntry $entry, string $memberKey): ?string
    {
        return $entry->constNames[$memberKey]
            ?? $entry->enumCaseCanonicalNames[$memberKey]
            ?? null;
    }

    /**
     * Class entry that holds an inherited (parent/interface) constant value (#25910, #25929).
     */
    private function resolveInheritedClassConstantHolding(ClassEntry $entry, string $memberKey): ?ClassEntry
    {
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            if (isset($iface->constants[$memberKey])) {
                return $iface;
            }
            $fromParentIface = $this->resolveInheritedClassConstantHolding($iface, $memberKey);
            if (null !== $fromParentIface) {
                return $fromParentIface;
            }
        }
        if (null !== $entry->parentLc && isset($this->context->classes[$entry->parentLc])) {
            $parent = $this->context->classes[$entry->parentLc];
            if (isset($parent->constants[$memberKey])) {
                $vis = $parent->constVisibility[$memberKey] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                    return $this->resolveInheritedClassConstantHolding($parent, $memberKey);
                }

                return $parent;
            }

            return $this->resolveInheritedClassConstantHolding($parent, $memberKey);
        }

        return null;
    }

}
