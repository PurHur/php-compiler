<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\CompilerVersion;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Variable;
use PHPCfg\Op;
use PHPCfg\Op\Stmt\ClassMethod;
use PHPCfg\Op\Stmt\Property as CfgProperty;
use PHPCfg\Op\Stmt\TraitUse;
use PHPCfg\Op\Terminal\Const_ as ClassConstDecl;
use PHPCfg\Script;

/**
 * PHP 8.3 #[\Override] compile-time validation (Zend zend_compile_override_attribute).
 */
final class OverrideValidator
{
    /**
     * Defer #[\Override] checks until all classes in the compile unit are registered (#9721).
     *
     * php-src: zend_compile_override_attribute() runs after the full class table for the unit exists.
     */
    public static function validateScript(Script $script): void
    {
        if (!CompilerVersion::supportsOverrideAttribute()) {
            return;
        }

        $registry = self::buildRegistry($script);
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Interface_) {
                $name = self::staticNameFromOperand($child->name);
                if (null === $name) {
                    continue;
                }
                $extends = self::interfaceNamesFromOperands($child->extends);
                self::validateClassBody($child->stmts, $name, null, $extends, $registry);
                continue;
            }
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $name = self::staticNameFromOperand($child->name);
            if (null === $name) {
                continue;
            }
            $parentLc = null;
            if (null !== $child->extends) {
                $parentName = self::staticNameFromOperand($child->extends);
                if (null !== $parentName) {
                    $parentLc = strtolower(ltrim($parentName, '\\'));
                }
            }
            $interfaceLcs = self::interfaceNamesFromOperands($child->implements);
            self::validateClassBody($child->stmts, $name, $parentLc, $interfaceLcs, $registry);
            self::validateTraitUsesInClass($child->stmts, $name, $parentLc, $interfaceLcs, $registry);
        }
    }

    public static function hasOverrideAttribute(array $attributeNames): bool
    {
        foreach ($attributeNames as $name) {
            $normalized = strtolower(ltrim($name, '\\'));
            if ('override' === $normalized || str_ends_with($normalized, '\\override')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \CompileError
     */
    public static function validateClassBody(
        CfgBlock $stmts,
        string $className,
        ?string $parentLc,
        array $interfaceLcs,
        ClassCompileRegistry $registry
    ): void {
        foreach ($stmts->children as $child) {
            if ($child instanceof ClassMethod) {
                $attributeNames = AttributeNames::fromOp($child);
                if (!self::hasOverrideAttribute($attributeNames)) {
                    continue;
                }
                self::validateOverrideMethod($className, $child, $parentLc, $interfaceLcs, $registry, $className, $stmts);

                continue;
            }
            if ($child instanceof CfgProperty) {
                $attributeNames = AttributeNames::fromOp($child);
                if (!self::hasOverrideAttribute($attributeNames)) {
                    continue;
                }
                self::validateOverrideProperty($className, $child, $parentLc, $interfaceLcs, $registry, $className, $stmts);

                continue;
            }
            if (!$child instanceof ClassConstDecl) {
                continue;
            }
            if (property_exists($child, 'isEnumCase') && $child->isEnumCase) {
                continue;
            }
            $attributeNames = AttributeNames::fromOp($child);
            if (!self::hasOverrideAttribute($attributeNames)) {
                continue;
            }
            self::validateOverrideConstant($className, $child, $parentLc, $interfaceLcs, $registry, $className, $stmts);
        }
    }

    /**
     * Validate #[\Override] on trait methods when the trait is used in a class (#6761).
     *
     * php-src: zend_compile_override_attribute() defers trait-body checks to trait binding.
     *
     * @throws \CompileError
     */
    public static function validateTraitUsesInClass(
        CfgBlock $classStmts,
        string $className,
        ?string $parentLc,
        array $interfaceLcs,
        ClassCompileRegistry $registry
    ): void {
        $visited = [];
        foreach (self::collectTraitLcs($classStmts, $registry, $visited) as $traitLc) {
            $traitStmts = $registry->getTraitStmts($traitLc);
            if (null === $traitStmts) {
                continue;
            }
            $traitDisplay = $registry->traitDisplayName($traitLc);
            foreach ($traitStmts->children as $child) {
                if (!$child instanceof ClassMethod) {
                    continue;
                }
                $attributeNames = AttributeNames::fromOp($child);
                if (!self::hasOverrideAttribute($attributeNames)) {
                    continue;
                }
                self::validateOverrideMethod($traitDisplay, $child, $parentLc, $interfaceLcs, $registry, $className);
            }
        }
    }

    /**
     * @throws \CompileError
     */
    private static function validateOverrideProperty(
        string $ownerDisplay,
        CfgProperty $property,
        ?string $parentLc,
        array $interfaceLcs,
        ClassCompileRegistry $registry,
        string $childClassName,
        ?CfgBlock $classStmts = null
    ): void {
        $propertyName = self::staticNameFromOperand($property->name);
        if (null === $propertyName) {
            return;
        }
        $propertyLc = strtolower($propertyName);
        $childClassLc = strtolower(ltrim($childClassName, '\\'));
        $hasTraitParent = false;
        if (null !== $classStmts) {
            $visited = [];
            foreach (self::collectTraitLcs($classStmts, $registry, $visited) as $traitLc) {
                if ($registry->hasPropertyInTrait($traitLc, $propertyLc)) {
                    $hasTraitParent = true;
                    break;
                }
            }
        }
        if (
            !$registry->hasOverridableProperty($parentLc, $interfaceLcs, $propertyLc, $childClassLc)
            && !$hasTraitParent
        ) {
            throw new \CompileError(sprintf(
                '%s::$%s has #[\Override] attribute, but no matching parent property exists',
                ltrim($ownerDisplay, '\\'),
                $propertyName
            ));
        }
    }

    /**
     * @throws \CompileError
     */
    private static function validateOverrideConstant(
        string $ownerDisplay,
        ClassConstDecl $constDecl,
        ?string $parentLc,
        array $interfaceLcs,
        ClassCompileRegistry $registry,
        string $childClassName,
        ?CfgBlock $classStmts = null
    ): void {
        $constName = self::staticNameFromOperand($constDecl->name);
        if (null === $constName) {
            return;
        }
        $constLc = strtolower($constName);
        $childClassLc = strtolower(ltrim($childClassName, '\\'));
        $hasTraitParent = false;
        if (null !== $classStmts) {
            $visited = [];
            foreach (self::collectTraitLcs($classStmts, $registry, $visited) as $traitLc) {
                if ($registry->hasConstantInTrait($traitLc, $constLc)) {
                    $hasTraitParent = true;
                    break;
                }
            }
        }
        if (
            !$registry->hasOverridableConstant($parentLc, $interfaceLcs, $constLc, $childClassLc)
            && !$hasTraitParent
        ) {
            throw new \CompileError(sprintf(
                '%s::%s has #[\Override] attribute, but no matching parent constant exists',
                ltrim($ownerDisplay, '\\'),
                $constName
            ));
        }
    }

    /**
     * @throws \CompileError
     */
    private static function validateOverrideMethod(
        string $ownerDisplay,
        ClassMethod $method,
        ?string $parentLc,
        array $interfaceLcs,
        ClassCompileRegistry $registry,
        string $childClassName,
        ?CfgBlock $classStmts = null
    ): void {
        $methodLc = strtolower($method->func->name);
        $childClassLc = strtolower(ltrim($childClassName, '\\'));
        $traitParent = null;
        if (null !== $classStmts) {
            $composed = TraitComposedMethodResolver::resolve($classStmts, $registry);
            $traitParent = $composed[$methodLc]
                ?? TraitComposedMethodResolver::resolveAliasedOriginalMethods($classStmts, $registry)[$methodLc]
                ?? null;
        }
        if (
            !$registry->hasOverridableMethod($parentLc, $interfaceLcs, $methodLc, $childClassLc)
            && null === $traitParent
        ) {
            throw new \CompileError(sprintf(
                '%s::%s() has #[\Override] attribute, but no matching parent method exists',
                ltrim($ownerDisplay, '\\'),
                $method->func->name
            ));
        }
        $ownerLc = strtolower(ltrim($ownerDisplay, '\\'));
        $childSig = MethodSig::fromFunc($method->func, $ownerLc);
        $parent = $registry->findOverriddenMethod($parentLc, $interfaceLcs, $methodLc, $childClassLc)
            ?? $traitParent;
        if (null !== $parent) {
            $msg = InheritanceVariance::methodCompatibilityError(
                ltrim($ownerDisplay, '\\'),
                $methodLc,
                $childSig,
                $parent['ownerDisplay'],
                $parent['sig'],
                fn (string $subtype, string $supertype): bool => $registry->isClassSubtypeOf($subtype, $supertype),
                fn (string $classLc, string $interfaceLc): bool => $registry->classImplementsInterface($classLc, $interfaceLc)
            );
            if (null !== $msg) {
                throw new \CompileError($msg);
            }
        }
    }

    /**
     * @param array<string, true> $visited
     *
     * @return list<string>
     */
    private static function collectTraitLcs(
        CfgBlock $stmts,
        ClassCompileRegistry $registry,
        array &$visited
    ): array {
        $traits = [];
        foreach ($stmts->children as $child) {
            if (!$child instanceof TraitUse) {
                continue;
            }
            foreach ($child->traits as $traitOperand) {
                $traitLc = self::operandLcName($traitOperand);
                if (null === $traitLc || isset($visited[$traitLc])) {
                    continue;
                }
                $visited[$traitLc] = true;
                $traits[] = $traitLc;
                $nested = $registry->getTraitStmts($traitLc);
                if (null !== $nested) {
                    foreach (self::collectTraitLcs($nested, $registry, $visited) as $nestedLc) {
                        $traits[] = $nestedLc;
                    }
                }
            }
        }

        return $traits;
    }

    private static function operandLcName(Operand $op): ?string
    {
        if ($op instanceof Literal && is_string($op->value)) {
            return strtolower(ltrim($op->value, '\\'));
        }
        if ($op instanceof Variable) {
            return self::operandLcName($op->name);
        }

        return null;
    }

    private static function buildRegistry(Script $script): ClassCompileRegistry
    {
        $registry = new ClassCompileRegistry();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Trait_) {
                $name = self::staticNameFromOperand($child->name);
                if (null !== $name) {
                    $registry->registerTrait($name, $child->stmts);
                }
                continue;
            }
            if ($child instanceof Op\Stmt\Interface_) {
                $name = self::staticNameFromOperand($child->name);
                if (null === $name) {
                    continue;
                }
                $registry->registerInterface($name, self::interfaceNamesFromOperands($child->extends), $child->stmts);
            }
        }
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $name = self::staticNameFromOperand($child->name);
            if (null === $name) {
                continue;
            }
            $parentLc = null;
            if (null !== $child->extends) {
                $parentName = self::staticNameFromOperand($child->extends);
                if (null !== $parentName) {
                    $parentLc = strtolower(ltrim($parentName, '\\'));
                }
            }
            $registry->registerClass(
                $name,
                $parentLc,
                self::interfaceNamesFromOperands($child->implements),
                $child->stmts
            );
        }

        return $registry;
    }

    /**
     * @param list<Operand> $operands
     *
     * @return list<string>
     */
    private static function interfaceNamesFromOperands(array $operands): array
    {
        $names = [];
        foreach ($operands as $operand) {
            $name = self::staticNameFromOperand($operand);
            if (null !== $name) {
                $names[] = strtolower(ltrim($name, '\\'));
            }
        }

        return $names;
    }

    private static function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Variable) {
            return self::staticNameFromOperand($op->name);
        }

        return null;
    }
}
