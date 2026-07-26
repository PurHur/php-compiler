<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PhpParser\Node\Stmt\Class_ as ClassNode;

/**
 * Compile-time check: non-private property types are invariant across inheritance
 * (zend_inheritance.c do_inherit_property / verify_property_type_compatibility, #23505).
 *
 * php-src-strict: child may not change, add, or remove a parent property type.
 * Private parent properties may be redeclared freely.
 */
final class TypedPropertyInheritCheck
{
    /**
     * @var array<string, array{
     *     display: string,
     *     extends: ?string,
     *     properties: array<string, array{display: string, type: ?TypeSig, private: bool, static: bool}>
     * }>
     */
    private array $classes = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verify();
    }

    private function collect(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_) {
                $this->collectClass($child);
            }
        }
    }

    private function collectClass(Op\Stmt\Class_ $class): void
    {
        $lc = $this->operandLcName($class->name);
        if (null === $lc) {
            return;
        }
        $parentLc = null !== $class->extends ? $this->operandLcName($class->extends) : null;
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'extends' => $parentLc,
            'properties' => $this->collectProperties($class),
        ];
    }

    /**
     * @return array<string, array{display: string, type: ?TypeSig, private: bool, static: bool}>
     */
    private function collectProperties(Op\Stmt\Class_ $class): array
    {
        $properties = [];
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\Property) {
                $propDisplay = $this->propertyDisplayName($member->name);
                $propLc = strtolower($propDisplay);
                $vis = (int) $member->visibility;
                $properties[$propLc] = [
                    'display' => $propDisplay,
                    'type' => TypeSig::fromCfgPropertyType($member->declaredType ?? null),
                    'private' => 0 !== ($vis & ClassNode::MODIFIER_PRIVATE),
                    'static' => (bool) $member->static,
                ];
                continue;
            }
            if (!$member instanceof Op\Stmt\ClassMethod || '__construct' !== $member->func->name) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!($param instanceof Op\Expr\Param)) {
                    continue;
                }
                if (!property_exists($param, 'promotionFlags') || 0 === (int) $param->promotionFlags) {
                    continue;
                }
                if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                    continue;
                }
                $propDisplay = $param->name->value;
                $propLc = strtolower($propDisplay);
                $flags = (int) $param->promotionFlags;
                $properties[$propLc] = [
                    'display' => $propDisplay,
                    'type' => TypeSig::fromCfgPropertyType($param->declaredType ?? null),
                    'private' => 0 !== ($flags & ClassNode::MODIFIER_PRIVATE),
                    'static' => false,
                ];
            }
        }

        return $properties;
    }

    private function verify(): void
    {
        foreach ($this->classes as $childLc => $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc || '' === $parentLc) {
                continue;
            }
            foreach ($class['properties'] as $propLc => $childProp) {
                $inherited = $this->findInheritedProperty($parentLc, $propLc);
                if (null === $inherited) {
                    continue;
                }
                $parentProp = $inherited['prop'];
                if ($parentProp['private']) {
                    continue;
                }
                $parentType = $parentProp['type'];
                $childType = $childProp['type'];
                if (TypeSig::propertyTypesAreInvariant(
                    $parentType,
                    $childType,
                    $inherited['ownerLc'],
                    $childLc
                )) {
                    continue;
                }
                if (null === $parentType && null !== $childType) {
                    // PHP 8.2 wording (docker profile); master uses "must be omitted…".
                    throw new \CompileError(sprintf(
                        'Type of %s::$%s must not be defined (as in class %s)',
                        $class['display'],
                        $childProp['display'],
                        $inherited['ownerDisplay']
                    ));
                }
                $required = null !== $parentType ? $parentType->format() : '';
                throw new \CompileError(sprintf(
                    'Type of %s::$%s must be %s (as in class %s)',
                    $class['display'],
                    $childProp['display'],
                    $required,
                    $inherited['ownerDisplay']
                ));
            }
        }
    }

    /**
     * @return array{prop: array{display: string, type: ?TypeSig, private: bool, static: bool}, ownerLc: string, ownerDisplay: string}|null
     */
    private function findInheritedProperty(string $startParentLc, string $propLc): ?array
    {
        $current = $startParentLc;
        $visited = [];
        $guard = 0;
        while (null !== $current && '' !== $current && !isset($visited[$current])) {
            if (++$guard > 256) {
                break;
            }
            $visited[$current] = true;
            $type = $this->classes[$current] ?? null;
            if (null !== $type && isset($type['properties'][$propLc])) {
                return [
                    'prop' => $type['properties'][$propLc],
                    'ownerLc' => $current,
                    'ownerDisplay' => $type['display'],
                ];
            }
            $current = $type['extends'] ?? null;
        }

        return null;
    }

    private function propertyDisplayName(Operand $op): string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->propertyDisplayName($op->name);
        }

        return 'property';
    }

    private function operandLcName(Operand $op): ?string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function operandDisplayName(Operand $op, string $fallbackLc): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallbackLc;
        }
        $short = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));
            $short = end($parts) ?: $name;
        }

        return $short;
    }

    private function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }
}
