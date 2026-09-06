<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

/**
 * Parent/interface inheritance + class define / class-const declare for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code inheritFromInterfaces} through
 * {@code applyClassConstDeclaration} (php-src Zend/zend_inheritance.c parent and
 * interface merge; Zend/zend_compile.c zend_do_early_binding / class const declare).
 * Concern trait — same namespace as parent so relative Frame / OpCode / Block
 * helpers resolve. Move-only; no new C ABI.
 */
trait ClassInheritDefineAndConstDeclare
{

    protected function inheritFromInterfaces(ClassEntry $entry): void
    {
        $entryLc = strtolower(ltrim($entry->name, '\\'));
        // Interfaces already satisfied by a parent — zend_inheritance.c does not re-check
        // inherited method bodies against them (only overrides declared on this class) (#25868).
        $inheritedIfaceSet = [];
        if (null !== $entry->parentLc && isset($this->context->classes[$entry->parentLc])) {
            foreach ($this->context->classes[$entry->parentLc]->interfaces as $parentIfaceLc) {
                $inheritedIfaceSet[$parentIfaceLc] = true;
            }
        }
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            $this->inheritInterfacePropertyRules($entry, $iface);
            $this->inheritInterfacePropertyHooks($entry, $iface);
            // Cross-file interface LSP (same-script covered by InheritanceVariance) (#25384).
            $ifaceInheritedFromParent = isset($inheritedIfaceSet[$ifaceLc]);
            foreach ($entry->methods as $methodLc => $_) {
                if ($ifaceInheritedFromParent) {
                    $declLc = $entry->methodDeclaringClassLc[$methodLc] ?? $entryLc;
                    if ($declLc !== $entryLc) {
                        continue;
                    }
                }
                if (isset($iface->methods[$methodLc]) || isset($iface->abstractMethods[$methodLc])) {
                    $this->rejectIncompatibleChildMethodSignature($entry, $iface, $methodLc);
                }
            }
            foreach ($iface->constants as $name => $value) {
                if (isset($entry->constants[$name])) {
                    $existingDeclLc = $entry->constDeclaringClassLc[$name] ?? $entryLc;
                    $incomingDeclLc = $iface->constDeclaringClassLc[$name]
                        ?? strtolower(ltrim($iface->name, '\\'));
                    // Two different declaring interfaces contributing the same constant name
                    // → Zend E_COMPILE_ERROR (do_inherit_iface_constant). Shared parent iface
                    // (same declaring lc) is fine; class/enum body own constants use the
                    // final-override path below (#26672 require/include + #24699).
                    if ($existingDeclLc !== $incomingDeclLc && $existingDeclLc !== $entryLc) {
                        $constDisplay = $entry->constNames[$name]
                            ?? $iface->constNames[$name]
                            ?? $name;
                        $subjectKind = $entry->isInterface ? 'Interface' : ($entry->isEnum ? 'Enum' : 'Class');
                        $subjectDisplay = $this->ambiguousIfaceConstSubjectDisplay($entry);
                        throw new \CompileError(sprintf(
                            '%s %s inherits both %s::%s and %s::%s, which is ambiguous',
                            $subjectKind,
                            $subjectDisplay,
                            $this->ambiguousIfaceConstOwnerDisplay($existingDeclLc),
                            $constDisplay,
                            $this->ambiguousIfaceConstOwnerDisplay($incomingDeclLc),
                            $constDisplay
                        ));
                    }
                    // Class/interface body redeclared a final interface constant (#22329).
                    $this->rejectChildOverrideOfFinalClassConst($entry, $iface, $name);
                    continue;
                }
                $entry->constants[$name] = $value;
                if (isset($iface->constNames[$name])) {
                    $entry->constNames[$name] = $iface->constNames[$name];
                }
                $entry->constDeclaringClassLc[$name] = $iface->constDeclaringClassLc[$name]
                    ?? strtolower(ltrim($iface->name, '\\'));
                if (isset($iface->constVisibility[$name])) {
                    $entry->constVisibility[$name] = $iface->constVisibility[$name];
                }
                // Propagate #[\Deprecated] so C::X (implements I) emits like Zend (#29380).
                if (isset($iface->constDeprecated[$name])) {
                    $entry->constDeprecated[$name] = $iface->constDeprecated[$name];
                }
                if (isset($iface->constFinal[$name])) {
                    $entry->constFinal[$name] = true;
                }
            }
        }
    }

    /** Short display name for ambiguous-iface-const fatals (Zend zend_inheritance.c, #26672). */
    private function ambiguousIfaceConstSubjectDisplay(ClassEntry $entry): string
    {
        $name = $entry->name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
    }

    /** Declaring interface display for ambiguous-iface-const fatals (#26672). */
    private function ambiguousIfaceConstOwnerDisplay(string $lc): string
    {
        if (isset($this->context->classes[$lc])) {
            return $this->ambiguousIfaceConstSubjectDisplay($this->context->classes[$lc]);
        }

        return $lc;
    }

    /**
     * When an interface is declared after its implementors, merge its constants (#9302, zend_enum.c).
     */
    protected function propagateInterfaceConstantsToImplementors(string $ifaceLc): void
    {
        foreach ($this->context->classes as $entry) {
            if (!in_array($ifaceLc, $entry->interfaces, true)) {
                continue;
            }
            $this->inheritFromInterfaces($entry);
        }
    }

    /**
     * Resolve class constants inherited from interfaces (forward-referenced implements, #9302).
     */
    protected function resolveInheritedClassConstant(ClassEntry $entry, string $memberLc): ?Variable
    {
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            if (isset($iface->constants[$memberLc])) {
                return $iface->constants[$memberLc];
            }
            $fromParentIface = $this->resolveInheritedClassConstant($iface, $memberLc);
            if (null !== $fromParentIface) {
                return $fromParentIface;
            }
        }
        if (null !== $entry->parentLc && isset($this->context->classes[$entry->parentLc])) {
            $parent = $this->context->classes[$entry->parentLc];
            if (isset($parent->constants[$memberLc])) {
                $vis = $parent->constVisibility[$memberLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                // Skip private parent constants — same rule as inheritFromParent (#19615).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                    return $this->resolveInheritedClassConstant($parent, $memberLc);
                }

                return $parent->constants[$memberLc];
            }

            return $this->resolveInheritedClassConstant($parent, $memberLc);
        }

        return null;
    }

    /**
     * Merge asymmetric set visibility and parent-interface property declares (#4876).
     */
    protected function inheritInterfacePropertyRules(ClassEntry $entry, ClassEntry $iface): void
    {
        foreach ($iface->properties as $ifaceProp) {
            $propLc = strtolower($ifaceProp->name);
            $matched = false;
            foreach ($entry->properties as $classProp) {
                if (strtolower($classProp->name) !== $propLc) {
                    continue;
                }
                $matched = true;
                if (0 !== $ifaceProp->setVisibility) {
                    $classProp->setVisibility = $ifaceProp->setVisibility;
                }
                if (0 !== $ifaceProp->getVisibility) {
                    $classProp->getVisibility = $ifaceProp->getVisibility;
                }
                if ($ifaceProp->asymmetricExplicitRead) {
                    $classProp->asymmetricExplicitRead = true;
                }
                break;
            }
            if (!$matched && $entry->isInterface) {
                $entry->properties[] = $this->cloneClassPropertyForEntry($ifaceProp, $entry);
            }
        }
    }

    /**
     * Merge interface abstract property-hook metadata into implementing classes (#6620, zend_property_hooks.c).
     */
    protected function inheritInterfacePropertyHooks(ClassEntry $entry, ClassEntry $iface): void
    {
        $ifaceLc = strtolower($iface->name);
        if (!isset($this->context->propertyHookRegistry[$ifaceLc])) {
            return;
        }
        $childLc = strtolower($entry->name);
        foreach ($this->context->propertyHookRegistry[$ifaceLc] as $prop => $meta) {
            $propLc = strtolower($prop);
            $classProp = null;
            foreach ($entry->properties as $candidate) {
                if (strtolower($candidate->name) === $propLc) {
                    $classProp = $candidate;
                    break;
                }
            }
            if (null === $classProp) {
                if (!$entry->isInterface) {
                    continue;
                }
                if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                    $this->context->propertyHookRegistry[$childLc][$prop] = $meta;
                }

                continue;
            }
            $mergeMeta = $this->propertyHookMetaForInheritedBackingField($entry, $classProp, $meta, $childLc, $prop);
            if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                $this->context->propertyHookRegistry[$childLc][$prop] = $mergeMeta;
            }
            $this->linkPropertyHooks($entry, $classProp);
        }
    }

    /**
     * Merge abstract-class property-hook metadata into subclasses (#6634, zend_property_hooks.c).
     */
    protected function inheritParentPropertyHooks(ClassEntry $entry, ClassEntry $parent): void
    {
        $parentLc = strtolower($parent->name);
        if (!isset($this->context->propertyHookRegistry[$parentLc])) {
            return;
        }
        $childLc = strtolower($entry->name);
        foreach ($this->context->propertyHookRegistry[$parentLc] as $prop => $meta) {
            $propLc = strtolower($prop);
            $classProp = null;
            foreach ($entry->properties as $candidate) {
                if (strtolower($candidate->name) === $propLc) {
                    $classProp = $candidate;
                    break;
                }
            }
            if (null === $classProp) {
                continue;
            }
            $mergeMeta = $this->propertyHookMetaForInheritedBackingField($entry, $classProp, $meta, $childLc, $prop);
            if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                $this->context->propertyHookRegistry[$childLc][$prop] = $mergeMeta;
            }
            $this->linkPropertyHooks($entry, $classProp);
        }
    }

    /**
     * Implementing / subclass plain typed property satisfies interface or inherited hook stubs (#7311).
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function propertyHookMetaForInheritedBackingField(
        ClassEntry $entry,
        VM\ClassProperty $classProp,
        array $meta,
        string $childLc,
        string $prop
    ): array {
        if ($this->entryPropertyHasExplicitHookMethods($entry, $classProp->name)) {
            return $meta;
        }
        $childMeta = $this->context->propertyHookRegistry[$childLc][$prop]
            ?? $this->context->propertyHookRegistry[$childLc][strtolower($prop)]
            ?? null;
        if (is_array($childMeta) && !empty($childMeta['abstract']) && empty($childMeta['get']) && empty($childMeta['set'])) {
            return $meta;
        }

        return $this->sanitizePropertyHookMetaForBackingField($meta);
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function sanitizePropertyHookMetaForBackingField(array $meta): array
    {
        unset($meta['requiresGet'], $meta['requiresSet'], $meta['requiresUnset'], $meta['abstract'], $meta['virtual']);

        return $meta;
    }

    private function entryPropertyHasExplicitHookMethods(ClassEntry $entry, string $propName): bool
    {
        $getLc = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($propName));
        $setLc = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));
        $unsetLc = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propName));

        return isset($entry->methods[$getLc]) || isset($entry->methods[$setLc]) || isset($entry->methods[$unsetLc]);
    }

    /**
     * @param list<string> $rawPermits lowercase names from source (possibly unqualified)
     *
     * @return list<string>
     */
    protected function normalizeSealedPermits(string $sealedName, array $rawPermits): array
    {
        $sealedLc = strtolower(ltrim($sealedName, '\\'));
        $ns = '';
        if (false !== ($pos = strrpos($sealedLc, '\\'))) {
            $ns = substr($sealedLc, 0, $pos + 1);
        }
        $out = [];
        foreach ($rawPermits as $p) {
            $p = strtolower(ltrim($p, '\\'));
            if (str_contains($p, '\\')) {
                $out[] = $p;
            } else {
                $out[] = $ns.$p;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $implements lowercase interface names
     */
    protected function assertAllowedBySealedParents(string $childName, ?string $parentLc, array $implements): void
    {
        $childLc = strtolower(ltrim($childName, '\\'));
        if (null !== $parentLc && isset($this->context->classes[$parentLc])) {
            $parent = $this->context->classes[$parentLc];
            if ($parent->sealed && !VM\ClassSealed::childMayInherit($childLc, $parent->sealedPermits)) {
                $msg = [] === $parent->sealedPermits
                    ? VM\ClassSealed::cannotExtendMessage($childName, $parent->name)
                    : VM\ClassSealed::notInPermitsListMessage($childName, $parent->name);
                throw new \LogicException($msg);
            }
        }
        foreach ($implements as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            if ($iface->sealed && !VM\ClassSealed::childMayInherit($childLc, $iface->sealedPermits)) {
                throw new \LogicException(VM\ClassSealed::cannotImplementMessage($childName, $iface->name));
            }
        }
    }

    private function cloneStaticPropertyStorage(Variable $source): Variable
    {
        $resolved = $source->resolveIndirect();
        $clone = new Variable();
        if (VM\TypedPropertyCheck::isUninitialized($resolved)) {
            $clone->copyUninitializedStaticPropertySlot($resolved);
        } else {
            $clone->copyFrom($resolved);
        }
        // Preserve declared casing for property_exists() (#23532).
        if (null !== $source->objectPropertyName) {
            $clone->objectPropertyName = $source->objectPropertyName;
        } elseif (null !== $resolved->objectPropertyName) {
            $clone->objectPropertyName = $resolved->objectPropertyName;
        }
        if (null !== $source->staticPropertyClassLc) {
            $clone->staticPropertyClassLc = $source->staticPropertyClassLc;
        } elseif (null !== $resolved->staticPropertyClassLc) {
            $clone->staticPropertyClassLc = $resolved->staticPropertyClassLc;
        }

        return $clone;
    }

    protected function inheritFromParent(ClassEntry $entry): void
    {
        if (null === $entry->parentLc || !isset($this->context->classes[$entry->parentLc])) {
            return;
        }
        $parent = $this->context->classes[$entry->parentLc];
        // php-src zend_inheritance.c — parent must not be ZEND_ACC_TRAIT (#26537).
        if ($parent->isTrait) {
            throw new \CompileError(
                "Class {$entry->name} cannot extend trait {$parent->name}"
            );
        }
        // php-src zend_inheritance.c — cannot extend ZEND_ACC_FINAL (#21669, #3406).
        // Enums are implicitly final (zend_enum.c ZEND_ACC_FINAL; #26531).
        if ($parent->isFinal || $parent->isEnum) {
            throw new \CompileError(
                "Class {$entry->name} cannot extend final class {$parent->name}"
            );
        }
        foreach ($parent->interfaces as $iface) {
            if (!in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
        foreach ($parent->methods as $name => $method) {
            $vis = $parent->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
            // Private methods are not inherited into subclass tables (Zend zend_inheritance).
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                continue;
            }
            if (isset($entry->methods[$name]) || isset($entry->abstractMethods[$name])) {
                // Child (or trait) redeclared a non-private parent final method (#24884).
                // Same-script compile is covered by FinalMethodOverrideCheck; cross-eval needs
                // this runtime path (see final class const #22329 / final property #22988).
                // Abstract-only overrides live in abstractMethods, not methods (#25660).
                $this->rejectChildOverrideOfFinalMethod($entry, $parent, $name);
                // Cross-file / eval LSP: same-script InheritanceVariance never sees the parent (#25384).
                $this->rejectIncompatibleChildMethodSignature($entry, $parent, $name);
                continue;
            }
            // PDO_*_Ext driver methods stay on PDO only (#21552).
            if (isset($parent->methodNotInherited[$name])) {
                continue;
            }
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $vis;
            if (isset($parent->methodDeclaringClassLc[$name])) {
                $entry->methodDeclaringClassLc[$name] = $parent->methodDeclaringClassLc[$name];
            } else {
                // Builtin parents often omit declaring-class marks; still record the parent
                // so Reflection/LSP can find stub arginfo on the declarer (#25840).
                $entry->methodDeclaringClassLc[$name] = strtolower(ltrim($parent->name, '\\'));
            }
            if (isset($parent->methodParameterMetadata[$name])) {
                $entry->methodParameterMetadata[$name] = $parent->methodParameterMetadata[$name];
            }
            if (isset($parent->methodReturnDeclaredTypes[$name])) {
                $entry->methodReturnDeclaredTypes[$name] = $parent->methodReturnDeclaredTypes[$name];
            }
            if (isset($parent->methodDeprecated[$name])) {
                $entry->methodDeprecated[$name] = $parent->methodDeprecated[$name];
            }
            $entry->methodNames[$name] = $parent->methodNames[$name] ?? $name;
        }
        // Abstract parent methods are not in $parent->methods — still enforce LSP on overrides (#25384).
        foreach ($parent->abstractMethods as $name => $_) {
            if (!isset($entry->methods[$name])) {
                continue;
            }
            $vis = $parent->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                continue;
            }
            $this->rejectIncompatibleChildMethodSignature($entry, $parent, $name);
        }
        foreach ($parent->staticProperties as $name => $storage) {
            if (isset($entry->staticProperties[$name])) {
                // Child redeclared a parent final static — same-script compile is covered by
                // FinalPropertyOverrideCheck; cross-eval needs this runtime path (#24992, #22988).
                // php-src zend_inheritance.c — "Cannot override final property %s::$%s".
                $vis = $parent->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) === 0
                    && !empty($parent->staticPropertyFinal[$name])
                ) {
                    $this->rejectChildOverrideOfFinalStaticProperty($entry, $parent, $name);
                }

                continue;
            }
            // Inherited statics share parent storage (class-declared #4668; trait-composed #4670).
            $entry->staticProperties[$name] = $storage;
            if (isset($parent->traitStaticPropertyNames[$name])) {
                $entry->traitStaticPropertyNames[$name] = true;
            }
            if (isset($parent->staticPropertyVisibility[$name])) {
                $entry->staticPropertyVisibility[$name] = $parent->staticPropertyVisibility[$name];
            }
            if (isset($parent->staticPropertySetVisibility[$name])) {
                $entry->staticPropertySetVisibility[$name] = $parent->staticPropertySetVisibility[$name];
            }
            if (isset($parent->staticPropertyGetVisibility[$name])) {
                $entry->staticPropertyGetVisibility[$name] = $parent->staticPropertyGetVisibility[$name];
            }
            if (isset($parent->staticPropertyAsymmetricExplicitRead[$name])) {
                $entry->staticPropertyAsymmetricExplicitRead[$name] = $parent->staticPropertyAsymmetricExplicitRead[$name];
            }
            if (isset($parent->staticPropertyDeclaringClassLc[$name])) {
                $entry->staticPropertyDeclaringClassLc[$name] = $parent->staticPropertyDeclaringClassLc[$name];
            }
            if (isset($parent->staticPropertyFinal[$name])) {
                $entry->staticPropertyFinal[$name] = true;
            }
        }
        foreach ($parent->staticPropertyHooks as $name => $hooks) {
            if (!isset($entry->staticPropertyHooks[$name])) {
                $entry->staticPropertyHooks[$name] = $hooks;
            }
        }
        $childLc = strtolower($entry->name);
        $this->inheritParentPropertyHooks($entry, $parent);
        foreach ($parent->constants as $name => $value) {
            // Private class constants are not inherited (Zend zend_constants.c / #19615).
            // Child self::PRIVATE must be Undefined constant Child::X, not a visibility leak.
            $vis = $parent->constVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                continue;
            }
            if (isset($entry->constants[$name])) {
                // Child redeclared a parent (or grandparent) final constant — zend_inheritance.c (#22329).
                $this->rejectChildOverrideOfFinalClassConst($entry, $parent, $name);
                continue;
            }
            $entry->constants[$name] = $value;
            if (isset($parent->constNames[$name])) {
                $entry->constNames[$name] = $parent->constNames[$name];
            }
            $entry->constDeclaringClassLc[$name] = $parent->constDeclaringClassLc[$name]
                ?? strtolower(ltrim($parent->name, '\\'));
            if (isset($parent->constVisibility[$name])) {
                $entry->constVisibility[$name] = $parent->constVisibility[$name];
            }
            if (isset($parent->constDeprecated[$name])) {
                $entry->constDeprecated[$name] = $parent->constDeprecated[$name];
            }
            if (isset($parent->constFinal[$name])) {
                $entry->constFinal[$name] = true;
            }
            if (isset($parent->constDeclaredTypes[$name])) {
                $entry->constDeclaredTypes[$name] = $parent->constDeclaredTypes[$name];
            }
            if (isset($parent->constSourceLocations[$name])) {
                $entry->constSourceLocations[$name] = $parent->constSourceLocations[$name];
            }
        }
        foreach ($parent->propDeprecated as $name => $deprecated) {
            if (!isset($entry->propDeprecated[$name])) {
                $entry->propDeprecated[$name] = $deprecated;
            }
        }
        if (null === $entry->constructor && null !== $parent->constructor) {
            $entry->constructor = $parent->constructor;
        }
        if (null === $entry->destructor && null !== $parent->destructor) {
            $entry->destructor = $parent->destructor;
        }
        if ($parent->readonly) {
            $entry->readonly = true;
        }
        if ($parent->usesLazyGhostTrait) {
            $entry->usesLazyGhostTrait = true;
        }
        foreach ($parent->properties as $property) {
            $isPrivate = ($property->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
            $exists = false;
            $childRedeclare = null;
            foreach ($entry->properties as $existing) {
                if ($existing->name !== $property->name) {
                    continue;
                }
                // Parent private slots coexist with same-name child privates (#22521).
                if ($isPrivate) {
                    if ($existing->declaringClassLc === $property->declaringClassLc) {
                        $exists = true;
                        $childRedeclare = $existing;
                        break;
                    }
                    continue;
                }
                $exists = true;
                $childRedeclare = $existing;
                break;
            }
            if ($exists) {
                // Child redeclared a non-private parent final property (#22988, Zend/zend_inheritance.c).
                // Same-script compile is covered by FinalPropertyOverrideCheck; cross-eval needs
                // this runtime path (see final class const #22329).
                if (!$isPrivate && $property->propertyFinal) {
                    $this->rejectChildOverrideOfFinalProperty($entry, $property);
                }
                // Typed property invariance across eval/include (#23505, zend_inheritance.c).
                if (!$isPrivate && null !== $childRedeclare) {
                    $this->rejectIncompatibleChildPropertyType($entry, $property, $childRedeclare);
                }
                continue;
            }
            $entry->properties[] = $property;
        }
    }

    /**
     * Walk the class hierarchy for __call (Zend zend_std_get_method; dual-it proxies #24287).
     */
    protected function findMagicCallClass(string $lcClass): ?ClassEntry
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
            if (isset($class->methods['__call'])) {
                return $class;
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        return null;
    }

    /**
     * Walk the class hierarchy for __callStatic (Zend zend_std_get_static_method slow path, #3273).
     */
    protected function findMagicCallStaticClass(string $lcClass): ?ClassEntry
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
            if (isset($class->methods['__callstatic'])) {
                return $class;
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        return null;
    }

    /**
     * @return array{0: ClassEntry, 1: string}
     */
    protected function resolveStaticMethod(string $lcClass, string $methodLc, ?string $displayMethodName = null): array
    {
        $requestedLc = $lcClass;
        $visited = [];
        $abstractDecl = null;
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
            if (isset($class->methods[$methodLc])) {
                return [$class, $methodLc];
            }
            if (isset($class->abstractMethods[$methodLc])) {
                $abstractDecl ??= $class;
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        if (null !== $abstractDecl) {
            $declName = $abstractDecl->methodNames[$methodLc] ?? $methodLc;
            throw new \LogicException("Cannot call abstract method {$abstractDecl->name}::{$declName}()");
        }

        // Zend zend_execute_API.c — same wording for static and instance misses; keep source casing (#27921).
        $declClass = $this->context->classes[$requestedLc] ?? null;
        $classDisplay = null !== $declClass ? $declClass->name : $requestedLc;
        $methodDisplay = $displayMethodName ?? $methodLc;
        throw new \LogicException("Call to undefined method {$classDisplay}::{$methodDisplay}()");
    }

    protected function initArrayCallable(Frame $frame, Variable $callable): ?Frame
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            return $this->dispatchVmError(
                VM\CallableCheck::arrayCallbackTwoElementsMessage(),
                $frame
            );
        }
        $receiver = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if (Variable::TYPE_STRING === $receiver->type) {
            $class = $receiver->toString();
            if ('' === $class) {
                throw new \LogicException('Invalid array callable');
            }
            try {
                // Dynamic array callables do not resolve parent/self/static (#25625).
                $this->initStaticCallable($frame, $class.'::'.$methodName, false, false, false, true);
            } catch (\Error $e) {
                return $this->dispatchVmError($e->getMessage(), $frame);
            } catch (\LogicException $e) {
                return $this->dispatchVmError($e->getMessage(), $frame);
            }

            return null;
        }
        if (Variable::TYPE_OBJECT !== $receiver->type
            && Variable::TYPE_ENUM_CASE !== $receiver->type) {
            throw new \LogicException('Invalid array callable');
        }
        if (Variable::TYPE_ENUM_CASE === $receiver->type) {
            $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($receiver);
        }

        return $this->initMethodCall($frame, $receiver, $methodName);
    }

    /**
     * Declare a user class for JIT/AOT class-constant materialization (#19046, Zend/zend_compile.c).
     *
     * Registers methods (including __construct) and declared properties without re-running full
     * defineClass(), which would recursively materialize other class constants and hit incomplete
     * VM opcode paths. Properties are required so constructor promotion does not create dynamic
     * properties under file-scope {@code const C = new UserClass(...)} (#35196).
     */
    public function ensureClassDeclaredForConstMaterialization(string $name, Block $bodyBlock): void
    {
        $lcname = strtolower(ltrim($name, '\\'));
        if (isset($this->context->classes[$lcname])) {
            return;
        }
        $frame = $bodyBlock->getFrame($this->context);
        $entry = new ClassEntry(ltrim($name, '\\'));
        \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($entry);
        foreach ($bodyBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_PROPERTY === $op->type) {
                $propName = $frame->scope[$op->arg1]->toString();
                $prototype = $frame->scope[$op->arg3];
                $property = new VM\ClassProperty(
                    $propName,
                    null,
                    $prototype,
                    $op->propertyReadonly,
                    MethodVisibility::mask($op->propertyVisibility),
                    $lcname,
                    (int) ($op->propertySetVisibility ?? 0),
                    (int) ($op->propertyGetVisibility ?? 0),
                    (bool) ($op->propertyAsymmetricExplicitRead ?? false),
                    (bool) ($op->propertyLazy ?? false)
                );
                $property->fromConstructorPromotion = (bool) ($op->propertyFromConstructorPromotion ?? false);
                $entry->properties[] = $property;
                continue;
            }
            if (OpCode::TYPE_DECLARE_METHOD !== $op->type || null === $op->block1) {
                continue;
            }
            $methodName = strtolower($frame->scope[$op->arg1]->toString());
            $method = new Func\PHP($entry->name.'::'.$methodName, $op->block1);
            $entry->methods[$methodName] = $method;
            if ('__construct' === $methodName) {
                $entry->constructor = $method;
            }
        }
        $this->context->classes[$lcname] = $entry;
    }

    protected function defineClass(ClassEntry $entry, Block $block, ?Frame $warningFrame = null): void {
        $frame = $block->getFrame($this->context);
        $frame->vmContext = $this->context;
        $ownMethods = $this->classBodyOwnMethodNames($block, $frame);
        $pendingNewDefaultOps = [];
        /** @var list<string> */
        $pendingTraits = [];
        $classBodyOps = $block->opCodes;
        $classConstSegments = $this->collectClassConstSegments($classBodyOps, $frame);
        $deferredClassConstSegments = $this->deferredClassConstSegments($classConstSegments);
        $classConstSkipIndices = $this->classConstSegmentSkipIndices($deferredClassConstSegments);
        if ([] !== $deferredClassConstSegments) {
            $entry->forwardDeclaredConstNames = array_fill_keys(
                array_keys($classConstSegments),
                true
            );
        }
        $classBodyOpCount = \count($classBodyOps);
        for ($classBodyOpIndex = 0; $classBodyOpIndex < $classBodyOpCount; ++$classBodyOpIndex) {
            $op = $classBodyOps[$classBodyOpIndex];
            if (isset($classConstSkipIndices[$classBodyOpIndex])) {
                if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type && [] !== $pendingNewDefaultOps) {
                    $this->finalizePendingNewClassConst($frame, $block, $op, $pendingNewDefaultOps);
                    $pendingNewDefaultOps = [];
                }

                continue;
            }
            if ([] !== $pendingNewDefaultOps) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type || OpCode::TYPE_DECLARE_STATIC_PROPERTY === $op->type) {
                    $this->finalizePendingNewPropertyDefault($frame, $block, $entry, $op, $pendingNewDefaultOps);
                    $pendingNewDefaultOps = [];

                    continue;
                }
                if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                    $this->finalizePendingNewClassConst($frame, $block, $op, $pendingNewDefaultOps);
                    $pendingNewDefaultOps = [];
                } else {
                    $pendingNewDefaultOps[] = $op;

                    continue;
                }
            } elseif (OpCode::TYPE_NEW === $op->type) {
                $pendingNewDefaultOps = $this->collectPropertyDefaultNewPreludeOps($classBodyOps, $classBodyOpIndex);
                $pendingNewDefaultOps[] = $op;

                continue;
            } elseif ($this->isClassBodyConstInitOpcode($op->type)) {
                $this->executeClassBodyConstInitOpcode($frame, $op);

                continue;
            }
            if ($this->isClassBodyDefaultInitOpcode($op->type)) {
                if ($this->opcodePrecedesPropertyDefaultNew($classBodyOps, $classBodyOpIndex)) {
                    continue;
                }
                $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                $pendingTraits = [];
                $this->executeClassBodyDefaultInitOpcode($frame, $op);

                continue;
            }
            if (VM\ClassConstExpr::isSupportedOpcode($op->type)) {
                VM\ClassConstExpr::execute($this->context, $frame, $block, $op, $entry);

                continue;
            }
            switch ($op->type) {
                case OpCode::TYPE_USE_TRAIT:
                    $pendingTraits[] = $frame->scope[$op->arg1]->toString();
                    break;
                case OpCode::TYPE_TRAIT_USE_ADAPTATION:
                    $this->applyTraitUsesWithAdaptations($entry, $pendingTraits, $op->traitAdaptations, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    break;
                case OpCode::TYPE_DECLARE_PROPERTY:
                    VM\RedundantTrueFalseUnionCheck::assertPropertyOp($frame, $op);
                    VM\RedundantIterableUnionCheck::assertPropertyOp($frame, $op);
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $name = $frame->scope[$op->arg1];
                    $default = $this->resolveCompileTimePropertyDefaultSlot($frame, $block, $op->arg2);
                    $propLc = strtolower($name->toString());
                    $classLc = strtolower($entry->name);
                    $traitAbstractHookOverride = null;
                    $prototype = $frame->scope[$op->arg3];
                    $incoming = new VM\ClassProperty(
                        $name->toString(),
                        $default,
                        $prototype,
                        $op->propertyReadonly,
                        MethodVisibility::mask($op->propertyVisibility),
                        $classLc,
                        (int) ($op->propertySetVisibility ?? 0),
                        (int) ($op->propertyGetVisibility ?? 0),
                        (bool) ($op->propertyAsymmetricExplicitRead ?? false),
                        (bool) ($op->propertyLazy ?? false)
                    );
                    $incoming->fromConstructorPromotion = $op->propertyFromConstructorPromotion;
                    // php-src zend_API.c — private(set) ⇒ ZEND_ACC_FINAL (#23068).
                    $incoming->propertyFinal = (bool) ($op->propertyFinal ?? false)
                        || PropertyVisibility::isImplicitlyFinalFromPrivateSet(
                            (int) ($op->propertySetVisibility ?? 0)
                        );
                    if ($entry->readonly) {
                        $incoming->readonly = true;
                    }
                    $incoming->setVisibility = PropertyVisibility::withImplicitReadonlyProtectedSet(
                        $incoming->readonly,
                        MethodVisibility::mask($incoming->visibility),
                        (int) $incoming->setVisibility
                    );
                    if (PropertyVisibility::isImplicitlyFinalFromPrivateSet($incoming->setVisibility)) {
                        $incoming->propertyFinal = true;
                    }
                    foreach ($entry->properties as $idx => $existing) {
                        if (strtolower($existing->name) !== $propLc) {
                            continue;
                        }
                        $declaringLc = $existing->declaringClassLc;
                        $fromTrait = isset($entry->traitPropertySources[$propLc]);
                        // Trait imports remapped to composing class (#26593) still need class-body merge.
                        if ($declaringLc !== $classLc || $fromTrait) {
                            $traitOriginLc = $fromTrait
                                ? strtolower(ltrim($entry->traitPropertySources[$propLc], '\\'))
                                : $declaringLc;
                            $traitEntry = $this->context->classes[$traitOriginLc] ?? null;
                            $traitName = $entry->traitPropertySources[$propLc]
                                ?? (
                                    isset($this->context->classes[$declaringLc])
                                        ? $this->context->classes[$declaringLc]->name
                                        : $declaringLc
                                );
                            $existingHasHooks = (null !== $traitEntry
                                    && VM\AbstractPropertyHookCheck::propertyHasHooks(
                                        $traitEntry,
                                        $existing,
                                        $this->context
                                    ))
                                || VM\AbstractPropertyHookCheck::registryHasHooks(
                                    $this->context->propertyHookRegistry,
                                    $traitOriginLc,
                                    $existing->name
                                );
                            $incomingHasHooks = VM\AbstractPropertyHookCheck::propertyHasHooks(
                                $entry,
                                $incoming,
                                $this->context
                            ) || VM\AbstractPropertyHookCheck::registryHasHooks(
                                $this->context->propertyHookRegistry,
                                $entry->name,
                                $incoming->name
                            );
                            // Zend: either side hooked → compose Fatal (#30009); do not treat class
                            // redeclaration as implementing abstract trait hooks (#7316 was wrong).
                            if ($existingHasHooks || $incomingHasHooks) {
                                $this->throwTraitPropertyCompositionFatal(
                                    TraitCompositionConflictMessage::sameHookedClassTraitProperty(
                                        $entry->name,
                                        is_string($traitName) ? $traitName : (string) $traitName,
                                        $name->toString()
                                    ),
                                    $entry,
                                    null,
                                    $frame
                                );
                            }
                            // Class redeclare of trait property: identical → replace with class (#22850).
                            if (VM\TraitPropertyCompatibility::instancePropertiesCompatible($existing, $incoming)) {
                                unset($entry->properties[$idx]);
                                unset($entry->traitPropertySources[$propLc]);
                                $entry->properties = array_values($entry->properties);
                                break;
                            }
                            $this->throwTraitPropertyCompositionFatal(
                                TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                                    $entry->name,
                                    is_string($traitName) ? $traitName : (string) $traitName,
                                    $name->toString()
                                ),
                                $entry,
                                null,
                                $frame
                            );
                        }
                    }
                    $prop = $incoming;
                    $entry->properties[] = $prop;
                    if ([] !== $op->attributeNames) {
                        $entry->propertyAttributeNames[$propLc] = $op->attributeNames;
                    }
                    if ([] !== $op->attributeEntries) {
                        $entry->propertyAttributeEntries[$propLc] = $op->attributeEntries;
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $entry->propDeprecated[$propLc] = $op->deprecatedMetadata;
                    }
                    if (null !== $op->sourceLocation) {
                        $entry->propertySourceLocations[$propLc] = $op->sourceLocation;
                    }
                    break;
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    VM\RedundantTrueFalseUnionCheck::assertPropertyOp($frame, $op);
                    VM\RedundantIterableUnionCheck::assertPropertyOp($frame, $op);
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $name = strtolower($frame->scope[$op->arg1]->toString());
                    $classLc = strtolower($entry->name);
                    $storage = $this->cloneStaticPropertyStorage($frame->scope[$op->arg3]);
                    $default = $this->resolveCompileTimePropertyDefaultSlot($frame, $block, $op->arg2);
                    if (null !== $default) {
                        $storage->copyFrom($default);
                    }
                    $newVis = MethodVisibility::mask($op->propertyVisibility);
                    $newSetVis = (int) ($op->propertySetVisibility ?? 0);
                    $newGetVis = (int) ($op->propertyGetVisibility ?? 0);
                    $newAsym = (bool) ($op->propertyAsymmetricExplicitRead ?? false);
                    if (isset($entry->staticProperties[$name])) {
                        $declaringLc = $entry->staticPropertyDeclaringClassLc[$name] ?? $classLc;
                        if ($declaringLc !== $classLc) {
                            $existing = $entry->staticProperties[$name];
                            if ($this->traitStaticPropertySlotsCompatible(
                                $existing,
                                (int) ($entry->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
                                (int) ($entry->staticPropertySetVisibility[$name] ?? 0),
                                (int) ($entry->staticPropertyGetVisibility[$name] ?? 0),
                                !empty($entry->staticPropertyAsymmetricExplicitRead[$name]),
                                $storage,
                                $newVis,
                                $newSetVis,
                                $newGetVis,
                                $newAsym
                            )) {
                                // Identical class+trait static — class wins declaring (#22850).
                            } else {
                                $traitName = isset($this->context->classes[$declaringLc])
                                    ? $this->context->classes[$declaringLc]->name
                                    : $declaringLc;
                                $this->throwTraitPropertyCompositionFatal(
                                    TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                                        $entry->name,
                                        $traitName,
                                        $name
                                    ),
                                    $entry,
                                    null,
                                    $frame
                                );
                            }
                        }
                    }
                    $this->linkStaticTypedPropertySlot(
                        $storage,
                        $entry,
                        $frame->scope[$op->arg1]->toString()
                    );
                    $entry->staticProperties[$name] = $storage;
                    $entry->staticPropertyVisibility[$name] = $newVis;
                    $entry->staticPropertySetVisibility[$name] = $newSetVis;
                    $entry->staticPropertyGetVisibility[$name] = $newGetVis;
                    if ($newAsym) {
                        $entry->staticPropertyAsymmetricExplicitRead[$name] = true;
                    }
                    $entry->staticPropertyDeclaringClassLc[$name] = strtolower($entry->name);
                    // php-src ZEND_ACC_FINAL on static props — inheritance + Reflection only (#23683).
                    if (!empty($op->propertyFinal)) {
                        $entry->staticPropertyFinal[$name] = true;
                    } else {
                        unset($entry->staticPropertyFinal[$name]);
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $entry->propDeprecated[$name] = $op->deprecatedMetadata;
                    }
                    if (null !== $op->sourceLocation) {
                        $entry->propertySourceLocations[$name] = $op->sourceLocation;
                    }
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $declaredName = $frame->scope[$op->arg1]->toString();
                    $name = strtolower($declaredName);
                    $vis = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $storedFlags = $block->constants[$op->arg3]->toInt();
                        $vis = MethodVisibility::mask($storedFlags);
                        if (($storedFlags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                            $vis |= \PHPCfg\Func::FLAG_STATIC;
                        }
                        if (($storedFlags & \PHPCfg\Func::FLAG_FINAL) !== 0) {
                            $vis |= \PHPCfg\Func::FLAG_FINAL;
                        }
                    }
                    if (($vis & \PHPCfg\Func::FLAG_FINAL) !== 0 && ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                        $warnLine = null !== $op->arg2 && $op->arg2 > 0 ? $op->arg2 : 0;
                        $handlerFrame = $warningFrame ?? $frame;
                        $warnFile = '' !== $handlerFrame->scriptPath ? $handlerFrame->scriptPath : null;
                        if (null === $warnFile || '' === $warnFile) {
                            $current = $this->context->scriptStack->current();
                            if ('' !== $current) {
                                $warnFile = $current;
                            }
                        }
                        $this->context->errors->languageWarning(
                            'Private methods cannot be final as they are never overridden by other classes',
                            $warnFile,
                            $warnLine,
                            $this->context,
                            $handlerFrame
                        );
                    }
                    $entry->methodVisibility[$name] = $vis;
                    $entry->methodDeclaringClassLc[$name] = strtolower($entry->name);
                    unset($entry->traitMethodSources[$name]);
                    $entry->methodNames[$name] = $declaredName;
                    if ([] !== $op->attributeNames) {
                        $entry->methodAttributeNames[$name] = $op->attributeNames;
                        $hookReflection = \PHPCompiler\SourcePreprocessor\PropertyHooks::reflectionNameFromHookMethod($name);
                        if (null !== $hookReflection) {
                            $entry->methodAttributeNames[$hookReflection] = $op->attributeNames;
                        }
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $entry->methodDeprecated[$name] = $op->deprecatedMetadata;
                    }
                    if ([] !== $op->attributeEntries) {
                        $entry->methodAttributeEntries[$name] = $op->attributeEntries;
                        $hookReflection = \PHPCompiler\SourcePreprocessor\PropertyHooks::reflectionNameFromHookMethod($name);
                        if (null !== $hookReflection) {
                            $entry->methodAttributeEntries[$hookReflection] = $op->attributeEntries;
                        }
                    }
                    if ([] !== $op->parameterMetadata) {
                        $entry->methodParameterMetadata[$name] = $op->parameterMetadata;
                    }
                    if (null !== $op->returnDeclaredType) {
                        $entry->methodReturnDeclaredTypes[$name] = $op->returnDeclaredType;
                    }
                    if (null !== $op->sourceLocation) {
                        $entry->methodSourceLocations[$name] = $op->sourceLocation;
                    }
                    if (null !== $op->block1) {
                        VM\RedundantTrueFalseUnionCheck::assertFunctionBlock(
                            $op->block1,
                            $frame,
                            $op->sourceLocation
                        );
                        VM\RedundantIterableUnionCheck::assertFunctionBlock(
                            $op->block1,
                            $frame,
                            $op->sourceLocation
                        );
                        $method = new Func\PHP($entry->name.'::'.$name, $op->block1);
                        $method->deprecated = $op->deprecatedMetadata;
                        $entry->methods[$name] = $method;
                        unset($entry->abstractMethods[$name]);
                        if ('__construct' === $name) {
                            $entry->constructor = $method;
                        }
                        if ('__destruct' === $name) {
                            $entry->destructor = $method;
                        }
                    } else {
                        $entry->abstractMethods[$name] = true;
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $this->applyClassConstDeclaration($entry, $block, $frame, $op);
                    break;
                default:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    throw new \LogicException(
                        'Other class body types are not jittable for now: '.opcode_type_name($op->type)
                    );
            }
        }
        $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
        if ([] !== $pendingNewDefaultOps) {
            throw new \LogicException('Unterminated property default `new` initializer in class body');
        }
        if ([] !== $deferredClassConstSegments) {
            $stillPending = $this->finalizeDeferredClassConstants(
                $entry,
                $block,
                $frame,
                $classBodyOps,
                $deferredClassConstSegments
            );
            if ([] !== $stillPending) {
                $this->context->deferredClassConstants[] = [
                    'entry' => $entry,
                    'block' => $block,
                    'frame' => $frame,
                    'classBodyOps' => $classBodyOps,
                    'segments' => $stillPending,
                ];
                $entry->pendingConstMaterialization = [
                    'block' => $block,
                    'frame' => $frame,
                    'classBodyOps' => $classBodyOps,
                    'segments' => $stillPending,
                ];
            }
        }
        foreach ($entry->properties as $prop) {
            $this->linkPropertyHooks($entry, $prop);
        }
        $this->linkStaticPropertyHooks($entry);
        if ($entry->isEnum) {
            VM\EnumSupport::ensureBuiltinCasesMethod($entry);
        }
        if ($entry->usesLazyGhostTrait) {
            VM\LazyGhostTraitSupport::ensureBuiltinLazyGhostMethods($entry);
        }
    }

    private function resolveClassConstDefineValue(Frame $frame, Block $block, OpCode $op): Variable
    {
        $value = $this->resolveClassConstInitializerValue($frame, $block, $op->arg2);

        return VM\EnumCaseSupport::materializeConstantValue($this->context, $value);
    }

    /**
     * Runtime `new` class-const inits land in frame scope; folded scalars in block constants (#9116).
     */
    private function resolveClassConstInitializerValue(Frame $frame, Block $block, int $slot): Variable
    {
        if (isset($frame->scope[$slot])) {
            $scoped = $frame->scope[$slot]->resolveIndirect();
            if (!$scoped->is(Variable::TYPE_NULL)) {
                $value = new Variable();
                $value->copyFrom($scoped);

                return $value;
            }
        }
        if (isset($block->constants[$slot])) {
            $value = new Variable();
            $value->copyFrom($block->constants[$slot]);

            return $value;
        }
        if (isset($frame->scope[$slot])) {
            $value = new Variable();
            $value->copyFrom($frame->scope[$slot]);

            return $value;
        }

        throw new \LogicException('Class constant value must be a compile-time constant');
    }

    /**
     * Folded parameter/property/static defaults live in block constants (#3803, #7399).
     */
    private function resolveCompileTimePropertyDefaultSlot(Frame $frame, Block $block, ?int $slot): ?Variable
    {
        if (null === $slot) {
            return null;
        }
        if (isset($block->constants[$slot])) {
            return VM\ClassConstMaterializer::detachConstantValue($block->constants[$slot]);
        }
        if (isset($frame->scope[$slot])) {
            return $frame->scope[$slot];
        }

        return null;
    }

    private function applyClassConstDeclaration(
        ClassEntry $entry,
        Block $block,
        Frame $frame,
        OpCode $op
    ): void {
        $canonical = $frame->scope[$op->arg1]->toString();
        // Case-sensitive key (Zend/zend_compile.c, #25910 fetch / #25929 declare).
        $name = ClassConstName::key($canonical);
        if ($entry->isEnum && $op->isEnumCaseDeclare) {
            $backingSource = VM\ClassConstExpr::resolveValue($frame, $block, $op->arg2);
            $caseBacking = new Variable(Variable::TYPE_NULL);
            $caseBacking->null();
            if (null !== $entry->backedType) {
                $caseBacking = clone VM\BackedEnum::caseBackingScalar(
                    $entry->backedType,
                    $backingSource
                );
            }
            $entry->constants[$name] = EnumCaseSupport::createCase(
                $entry,
                $canonical,
                $caseBacking
            );
            $entry->enumCaseCanonicalNames[$name] = $canonical;
            $entry->constNames[$name] = $canonical;
            $entry->enumCases[] = [
                'name' => $canonical,
                'value' => $caseBacking,
            ];
            if ([] !== $op->attributeEntries) {
                $entry->enumCaseAttributeEntries[$name] = $op->attributeEntries;
            }
            if (null !== $op->deprecatedMetadata) {
                $entry->constDeprecated[$name] = $op->deprecatedMetadata;
            }

            return;
        }
        $value = $this->resolveClassConstDefineValue($frame, $block, $op);
        if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
            $check = new Variable();
            $check->copyFrom($value);
            TypeCheck::assertClassConstantTypedValue(
                $check,
                $block->constants[$op->arg3],
                $canonical,
                $entry->name
            );
            $value->copyFrom($check);
        }
        $this->rejectIncompatibleTraitClassConstOverride($entry, $name, $canonical, $value);
        $entry->constants[$name] = $value;
        $entry->constNames[$name] = $canonical;
        $entry->constDeclaringClassLc[$name] = strtolower(ltrim($entry->name, '\\'));
        $entry->constVisibility[$name] = ClassConstVisibility::mask($op->classConstVisibilityFlags);
        unset($entry->traitConstSources[$name]);
        if ([] !== $op->attributeNames) {
            $entry->constAttributeNames[$name] = $op->attributeNames;
        }
        if ([] !== $op->attributeEntries) {
            $entry->constAttributeEntries[$name] = $op->attributeEntries;
        }
        if (null !== $op->deprecatedMetadata) {
            $entry->constDeprecated[$name] = $op->deprecatedMetadata;
        }
        if (null !== $op->sourceLocation) {
            $entry->constSourceLocations[$name] = $op->sourceLocation;
        }
        if (0 !== ($op->classConstVisibilityFlags & \PHPCfg\Func::FLAG_FINAL)) {
            $entry->constFinal[$name] = true;
        }
        if (isset($block->classConstDeclaredTypes[$name])) {
            $entry->constDeclaredTypes[$name] = $block->classConstDeclaredTypes[$name];
        }
    }

}
