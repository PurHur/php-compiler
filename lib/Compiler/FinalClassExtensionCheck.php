<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PhpParser\Node\Stmt\Class_;

/**
 * Compile-time check: non-final classes cannot extend a final parent (#3406).
 *
 * php-src: Zend/zend_compile.c — zend_compile_class_decl;
 * Zend/zend_inheritance.c — do_inheritance_on_class
 */
final class FinalClassExtensionCheck
{
    /** @var array<string, array{display: string, final: bool, extends: ?string}> */
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
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'final' => 0 !== ($class->flags & Class_::MODIFIER_FINAL),
            'extends' => $parentLc,
        ];
    }

    private function verify(): void
    {
        foreach ($this->classes as $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc || !isset($this->classes[$parentLc])) {
                continue;
            }
            if (!$this->classes[$parentLc]['final']) {
                continue;
            }
            throw new \CompileError(
                "Class {$class['display']} cannot extend final class {$this->classes[$parentLc]['display']}"
            );
        }
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
