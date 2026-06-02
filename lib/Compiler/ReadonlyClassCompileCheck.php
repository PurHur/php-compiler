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
 * per-property MODIFIER_READONLY cannot have default initializer
 */
final class ReadonlyClassCompileCheck
{
    /** @var array<string, array{display: string, readonly: bool, extends: ?string}> */
    private array $classes = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verifyInheritance();
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
        $this->verifyNoPropertyDefaults($class, $display, $readonly);
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

    private function verifyNoPropertyDefaults(Op\Stmt\Class_ $class, string $classDisplay, bool $classReadonly): void
    {
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
            if (null === $parentLc || !isset($this->classes[$parentLc])) {
                continue;
            }
            $parent = $this->classes[$parentLc];
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
}
