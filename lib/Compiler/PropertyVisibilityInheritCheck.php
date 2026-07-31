<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PhpParser\Node\Stmt\Class_ as ClassNode;

/**
 * Compile-time check: child property visibility must not be narrower than an
 * inherited (non-private) parent property (#25661).
 *
 * php-src: Zend/zend_inheritance.c — do_inherit_property / access level check
 * Message shapes:
 *   - parent public:  "Access level to B::$x must be public (as in class A)"
 *   - parent protected: "Access level to B::$x must be protected (as in class A) or weaker"
 *
 * Applies to instance and static properties (including constructor promotion).
 * Private parent properties are not inherited and may be freely redeclared.
 */
final class PropertyVisibilityInheritCheck
{
    private const VIS_PUBLIC = 1;
    private const VIS_PROTECTED = 2;
    private const VIS_PRIVATE = 3;

    /**
     * @var array<string, array{
     *     display: string,
     *     properties: array<string, array{display: string, vis: int, file: string, line: int}>,
     *     extends: ?string
     * }>
     */
    private array $types = [];

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
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'properties' => $this->collectProperties($class),
            'extends' => $parentLc,
        ];
    }

    /**
     * @return array<string, array{display: string, vis: int, file: string, line: int}>
     */
    private function collectProperties(Op\Stmt\Class_ $class): array
    {
        $properties = [];
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\Property) {
                $propDisplay = $this->propertyDisplayName($member->name);
                $propLc = strtolower($propDisplay);
                $properties[$propLc] = [
                    'display' => $propDisplay,
                    'vis' => $this->visibilityRank((int) $member->visibility),
                    'file' => $member->getFile(),
                    'line' => max(1, $member->getLine()),
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
                    'vis' => $this->visibilityRank($flags),
                    'file' => $param->getFile(),
                    'line' => max(1, $param->getLine()),
                ];
            }
        }

        return $properties;
    }

    private function verify(): void
    {
        foreach ($this->types as $type) {
            if (null === $type['extends'] || '' === $type['extends']) {
                continue;
            }
            foreach ($type['properties'] as $propLc => $childProp) {
                $parent = $this->findInheritedProperty($type['extends'], $propLc);
                if (null === $parent) {
                    continue;
                }
                // Child may widen or keep the same visibility; narrowing is fatal.
                if ($childProp['vis'] <= $parent['vis']) {
                    continue;
                }
                throw new CompileFatal(
                    $childProp['file'],
                    $childProp['line'],
                    $this->accessLevelMessage(
                        $type['display'],
                        $childProp['display'],
                        $parent['classDisplay'],
                        $parent['vis']
                    )
                );
            }
        }
    }

    /**
     * Walk the extends chain for the nearest non-private inherited property.
     *
     * @return array{classDisplay: string, vis: int}|null
     */
    private function findInheritedProperty(string $parentLc, string $propLc): ?array
    {
        $seen = [];
        $current = $parentLc;
        while ('' !== $current && !isset($seen[$current])) {
            $seen[$current] = true;
            if (!isset($this->types[$current])) {
                break;
            }
            $class = $this->types[$current];
            if (isset($class['properties'][$propLc])) {
                $prop = $class['properties'][$propLc];
                // Private parent properties are not inherited.
                if (self::VIS_PRIVATE === $prop['vis']) {
                    $current = $class['extends'] ?? '';
                    if (null === $current) {
                        break;
                    }

                    continue;
                }

                return [
                    'classDisplay' => $class['display'],
                    'vis' => $prop['vis'],
                ];
            }
            $current = $class['extends'] ?? '';
            if (null === $current) {
                break;
            }
        }

        return null;
    }

    private function accessLevelMessage(
        string $childClass,
        string $propName,
        string $parentClass,
        int $parentVis
    ): string {
        if (self::VIS_PUBLIC === $parentVis) {
            return sprintf(
                'Access level to %s::$%s must be public (as in class %s)',
                $childClass,
                $propName,
                $parentClass
            );
        }

        return sprintf(
            'Access level to %s::$%s must be protected (as in class %s) or weaker',
            $childClass,
            $propName,
            $parentClass
        );
    }

    private function visibilityRank(int $flags): int
    {
        if (0 !== ($flags & ClassNode::MODIFIER_PRIVATE)) {
            return self::VIS_PRIVATE;
        }
        if (0 !== ($flags & ClassNode::MODIFIER_PROTECTED)) {
            return self::VIS_PROTECTED;
        }

        return self::VIS_PUBLIC;
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
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
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
