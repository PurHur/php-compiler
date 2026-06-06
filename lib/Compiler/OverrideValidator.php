<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Variable;
use PHPCfg\Op\Stmt\ClassMethod;
use PHPCfg\Op\Stmt\TraitUse;

/**
 * PHP 8.3 #[\Override] compile-time validation (Zend zend_compile_override_attribute).
 */
final class OverrideValidator
{
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
            if (!$child instanceof ClassMethod) {
                continue;
            }
            $attributeNames = AttributeNames::fromOp($child);
            if (!self::hasOverrideAttribute($attributeNames)) {
                continue;
            }
            self::validateOverrideMethod($className, $child, $parentLc, $interfaceLcs, $registry);
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
                self::validateOverrideMethod($traitDisplay, $child, $parentLc, $interfaceLcs, $registry);
            }
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
        ClassCompileRegistry $registry
    ): void {
        $methodLc = strtolower($method->func->name);
        if (!$registry->hasOverridableMethod($parentLc, $interfaceLcs, $methodLc)) {
            throw new \CompileError(sprintf(
                '%s::%s() has #[\Override] attribute, but no matching parent method exists',
                ltrim($ownerDisplay, '\\'),
                $method->func->name
            ));
        }
        $ownerLc = strtolower(ltrim($ownerDisplay, '\\'));
        $childSig = MethodSig::fromFunc($method->func, $ownerLc);
        $parent = $registry->findOverriddenMethod($parentLc, $interfaceLcs, $methodLc);
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
}
