<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: horizontal trait method collisions without insteadof (#3416).
 *
 * php-src: Zend/zend_traits.c — zend_traits_get_method, horizontal conflict detection
 */
final class TraitCollisionCheck
{
    /** @var array<string, array{display: string, methods: array<string, true>}> */
    private array $traits = [];

    /** @var array<string, array{display: string, extends: ?string, ownMethods: array<string, true>, traitUses: list<list<string>>}> */
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
            if ($child instanceof Op\Stmt\Trait_) {
                $this->collectTrait($child);
            } elseif ($child instanceof Op\Stmt\Class_) {
                $this->collectClass($child);
            }
        }
    }

    private function collectTrait(Op\Stmt\Trait_ $trait): void
    {
        $lc = $this->operandLcName($trait->name);
        if (null === $lc) {
            return;
        }
        $this->traits[$lc] = [
            'display' => $this->operandDisplayName($trait->name, $lc),
            'methods' => $this->collectConcreteMethods($trait->stmts->children),
        ];
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
        $traitUses = [];
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\TraitUse) {
                if ([] !== $member->adaptations) {
                    continue;
                }
                $traits = [];
                foreach ($member->traits as $traitOperand) {
                    $traitLc = $this->operandLcName($traitOperand);
                    if (null !== $traitLc) {
                        $traits[] = $traitLc;
                    }
                }
                if ([] !== $traits) {
                    $traitUses[] = $traits;
                }
            }
        }
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'extends' => $parentLc,
            'ownMethods' => $this->collectConcreteMethods($class->stmts->children),
            'traitUses' => $traitUses,
        ];
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectConcreteMethods(array $members): array
    {
        $methods = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if ($this->methodHasBody($member)) {
                $methods[strtolower($member->func->name)] = true;
            }
        }

        return $methods;
    }

    private function methodHasBody(Op\Stmt\ClassMethod $method): bool
    {
        $cfg = $method->func->cfg;
        if (null === $cfg) {
            return false;
        }

        return [] !== $cfg->children;
    }

    private function verify(): void
    {
        foreach ($this->classes as $class) {
            $this->verifyClass($class);
        }
    }

    /**
     * @param array{display: string, extends: ?string, ownMethods: array<string, true>, traitUses: list<list<string>>} $class
     */
    private function verifyClass(array $class): void
    {
        $excluded = $class['ownMethods'];
        $current = $class['extends'];
        $visited = [];
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->classes[$current])) {
                break;
            }
            foreach ($this->classes[$current]['ownMethods'] as $name => $_) {
                $excluded[$name] = true;
            }
            $current = $this->classes[$current]['extends'];
        }

        /** @var array<string, string> method lc => trait display */
        $traitSources = [];
        foreach ($class['traitUses'] as $useGroup) {
            foreach ($useGroup as $traitLc) {
                if (!isset($this->traits[$traitLc])) {
                    continue;
                }
                $trait = $this->traits[$traitLc];
                foreach ($trait['methods'] as $methodLc => $_) {
                    if (isset($excluded[$methodLc])) {
                        continue;
                    }
                    if (isset($traitSources[$methodLc])) {
                        throw new \CompileError(
                            "Trait method {$trait['display']}::{$methodLc} has not been applied as "
                            ."{$class['display']}::{$methodLc}, because of collision with "
                            ."{$traitSources[$methodLc]}::{$methodLc}"
                        );
                    }
                    $traitSources[$methodLc] = $trait['display'];
                }
            }
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
