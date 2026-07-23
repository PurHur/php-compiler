<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassFinal;

/**
 * Compile-time checks for PHP 8.4 final properties (#22241, #22308).
 *
 * php-src: Zend/zend_compile.c — final property flags / pre-8.4 reject;
 * Zend/zend_inheritance.c — "Cannot override final property %s::$%s".
 */
final class FinalPropertyOverrideCheck
{
    /** @var array<string, array{display: string, extends: ?string, properties: array<string, array{final: bool, fromFlags: bool, display: string}>}> */
    private array $classes = [];

    /**
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    public static function validate(Script $script, array $propertyHookRegistry = []): void
    {
        $check = new self($propertyHookRegistry);
        $check->collect($script);
        $check->verifyUnsupportedFinals();
        $check->verifyOverrides();
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    private function __construct(
        private array $propertyHookRegistry = [],
    ) {
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
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'extends' => $parentLc,
            'properties' => $this->collectInstanceProperties($class, $lc),
        ];
    }

    /**
     * @return array<string, array{final: bool, fromFlags: bool, display: string}>
     */
    private function collectInstanceProperties(Op\Stmt\Class_ $class, string $classLc): array
    {
        $properties = [];
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\Property && !$member->static) {
                $propDisplay = $this->propertyDisplayName($member->name);
                $propLc = strtolower($propDisplay);
                $fromFlags = $this->isFinalFromFlags($member);
                $fromRegistry = $this->isFinalFromHookRegistry($classLc, $propDisplay);
                $properties[$propLc] = [
                    'final' => $fromFlags || $fromRegistry,
                    'fromFlags' => $fromFlags,
                    'display' => $propDisplay,
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
                $fromMarker = \PHPCompiler\Ast\FinalPromotedPropertyRewriter::isFinalFromAttributes(
                    $param->getAttributes()
                );
                $fromField = property_exists($param, 'promotionFinal') && $param->promotionFinal;
                $isFinal = $fromMarker || $fromField;
                // Always record promoted props so a non-final child redeclaration can
                // trip "Cannot override final property" against a final parent (#22451).
                $properties[$propLc] = [
                    'final' => $isFinal,
                    'fromFlags' => $isFinal,
                    'display' => $propDisplay,
                ];
            }
        }

        return $properties;
    }

    private function isFinalFromFlags(Op\Stmt\Property $member): bool
    {
        if (property_exists($member, 'propertyFlags')
            && ClassFinal::fromClassFlags((int) $member->propertyFlags)) {
            return true;
        }

        return ClassFinal::fromClassFlags((int) $member->visibility);
    }

    private function isFinalFromHookRegistry(string $classLc, string $propDisplay): bool
    {
        $hooks = $this->propertyHookRegistry[$classLc][$propDisplay]
            ?? $this->propertyHookRegistry[$classLc][strtolower($propDisplay)]
            ?? null;

        return is_array($hooks) && !empty($hooks['finalProperty']);
    }

    private function verifyUnsupportedFinals(): void
    {
        // Hooked finals (#16799) use the property-hook registry and are gated by
        // supportsPropertyHooks(); only plain MODIFIER_FINAL needs supportsFinalProperties().
        if (CompilerVersion::supportsFinalProperties()) {
            return;
        }
        foreach ($this->classes as $class) {
            foreach ($class['properties'] as $prop) {
                if (!empty($prop['fromFlags'])) {
                    // Zend 8.2 Fatal (zend_compile.c) — #22308.
                    throw new \CompileError(sprintf(
                        'Cannot declare property %s::$%s final, the final modifier is allowed only for methods, classes, and class constants',
                        $class['display'],
                        $prop['display']
                    ));
                }
            }
        }
    }

    private function verifyOverrides(): void
    {
        if (!CompilerVersion::supportsFinalProperties()
            && !CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        foreach ($this->classes as $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc || '' === $parentLc) {
                continue;
            }
            foreach ($class['properties'] as $propLc => $childProp) {
                $parentProp = $this->findInheritedProperty($parentLc, $propLc);
                if (null === $parentProp || !$parentProp['final']) {
                    continue;
                }
                // Plain final override requires 8.4 final-property support; hooked finals
                // already enforce via hooks path when property hooks are enabled.
                if ($parentProp['fromFlags'] && !CompilerVersion::supportsFinalProperties()) {
                    continue;
                }
                if (!$parentProp['fromFlags'] && !CompilerVersion::supportsPropertyHooks()) {
                    continue;
                }
                throw new \CompileError(sprintf(
                    'Cannot override final property %s::$%s',
                    $parentProp['ownerDisplay'],
                    $parentProp['display']
                ));
            }
        }
    }

    /**
     * @return array{final: bool, fromFlags: bool, display: string, ownerDisplay: string}|null
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
                $prop = $type['properties'][$propLc];

                return [
                    'final' => $prop['final'],
                    'fromFlags' => $prop['fromFlags'],
                    'display' => $prop['display'],
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
