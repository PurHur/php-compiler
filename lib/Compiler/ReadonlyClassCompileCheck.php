<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\VM\ClassReadonly;

/**
 * Compile-time checks for readonly class inheritance and property defaults (#3551, #3149).
 *
 * php-src: Zend/zend_compile.c — zend_compile_class_decl;
 * Zend/zend_inheritance.c — readonly parent/child checks;
 * per-property MODIFIER_READONLY cannot have default initializer;
 * PHP 8.3+ anonymous classes may use per-property `readonly` with defaults (#6724);
 * PHP 8.3+ `new readonly class` sets ZEND_ACC_READONLY on the anonymous class (#6991).
 */
final class ReadonlyClassCompileCheck
{
    /** @var array<string, array{display: string, readonly: bool, extends: ?string}> */
    private array $classes = [];

    /**
     * @param array<string, array{display: string, readonly: bool, extends: ?string}> $knownClasses
     *        Already-registered user classes (eval parent lookup, #7170).
     */
    public static function validate(Script $script, array $knownClasses = []): void
    {
        $check = new self($knownClasses);
        $check->collect($script);
        $check->verifyInheritance();
    }

    /**
     * @param array<string, array{display: string, readonly: bool, extends: ?string}> $knownClasses
     */
    private function __construct(private array $knownClasses = [])
    {
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
        $readonly = ClassReadonly::fromClassFlags($class->flags);
        $display = $this->operandDisplayName($class->name, $lc);
        if ($readonly) {
            AttributeNames::assertAllowDynamicPropertiesNotOnReadonlyClass(
                AttributeNames::fromOp($class),
                $display
            );
            $this->verifyReadonlyClassNoStaticProperties($class, $display);
        }
        $this->verifyNoPropertyDefaults($class, $display, $readonly);
        $this->verifyNoStaticReadonlyProperties($class, $display);
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $this->classes[$lc] = [
            'display' => $display,
            'readonly' => $readonly,
            'extends' => $parentLc,
        ];
    }

    private function verifyReadonlyClassNoStaticProperties(Op\Stmt\Class_ $class, string $classDisplay): void
    {
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\Property || !$member->static) {
                continue;
            }
            throw new \CompileError(
                "Readonly class {$classDisplay} cannot declare static properties"
            );
        }
    }

    private function verifyNoStaticReadonlyProperties(Op\Stmt\Class_ $class, string $classDisplay): void
    {
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\Property || !$member->static) {
                continue;
            }
            if (!$this->isCfgPropertyReadonly($member)) {
                continue;
            }
            $propName = $this->propertyDisplayName($member->name);
            throw new \CompileError(
                "Static property {$classDisplay}::\${$propName} cannot be readonly"
            );
        }
    }

    private function verifyNoPropertyDefaults(Op\Stmt\Class_ $class, string $classDisplay, bool $classReadonly): void
    {
        // php-src ZEND_ACC_ANON_READONLY: per-property readonly on anonymous classes (#6724).
        if ($this->isAnonymousClass($class->name)) {
            return;
        }

        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\Property) {
                continue;
            }
            $propertyReadonly = $classReadonly || $this->isCfgPropertyReadonly($member);
            if (!$propertyReadonly) {
                continue;
            }
            if (null === $member->defaultVar && null === $member->defaultBlock) {
                continue;
            }
            $propName = $this->propertyDisplayName($member->name);
            throw new \CompileError(
                "Readonly property {$classDisplay}::\${$propName} cannot have default value"
            );
        }
    }

    private function verifyInheritance(): void
    {
        foreach ($this->classes as $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc) {
                continue;
            }
            $parent = $this->classes[$parentLc] ?? $this->knownClasses[$parentLc] ?? null;
            if (null === $parent) {
                continue;
            }
            if ($parent['readonly'] && !$class['readonly']) {
                throw new \CompileError(
                    "Non-readonly class {$class['display']} cannot extend readonly class {$parent['display']}"
                );
            }
            if ($class['readonly'] && !$parent['readonly']) {
                throw new \CompileError(
                    "Readonly class {$class['display']} cannot extend non-readonly class {$parent['display']}"
                );
            }
        }
    }

    private function isCfgPropertyReadonly(Op\Stmt\Property $member): bool
    {
        if (property_exists($member, 'readonly') && $member->readonly) {
            return true;
        }
        if (property_exists($member, 'propertyFlags')
            && ClassReadonly::fromClassFlags($member->propertyFlags)) {
            return true;
        }

        return ClassReadonly::fromClassFlags($member->visibility);
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

    /** php-src: zend_compile.c — anonymous class names contain @anonymous. */
    private function isAnonymousClass(Operand $op): bool
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return false;
        }

        return str_contains($name, '@anonymous');
    }
}
