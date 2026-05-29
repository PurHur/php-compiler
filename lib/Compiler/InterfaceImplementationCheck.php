<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: concrete classes must implement all interface methods (#3386).
 *
 * php-src: Zend/zend_inheritance.c — zend_do_implement_interface, zend_verify_abstract_class
 */
final class InterfaceImplementationCheck
{
    /** @var array<string, array{display: string, extends: list<string>, methods: list<string>}> */
    private array $interfaces = [];

    /** @var array<string, array{display: string, abstract: bool, extends: ?string, implements: list<string>, methods: array<string, true>}> */
    private array $classes = [];

    /** @var array<string, array{display: string, methods: array<string, true>}> */
    private array $traits = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verify();
    }

    private function collect(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Interface_) {
                $this->collectInterface($child);
            } elseif ($child instanceof Op\Stmt\Trait_) {
                $this->collectTrait($child);
            } elseif ($child instanceof Op\Stmt\Class_) {
                $this->collectClass($child);
            }
        }
    }

    private function collectInterface(Op\Stmt\Interface_ $iface): void
    {
        $lc = $this->operandLcName($iface->name);
        if (null === $lc) {
            return;
        }
        $methods = [];
        foreach ($iface->stmts->children as $member) {
            if ($member instanceof Op\Stmt\ClassMethod) {
                $methods[strtolower($member->func->name)] = true;
            }
        }
        $extends = [];
        foreach ($iface->extends as $parentOperand) {
            $parentLc = $this->operandLcName($parentOperand);
            if (null !== $parentLc) {
                $extends[] = $parentLc;
            }
        }
        $this->interfaces[$lc] = [
            'display' => $this->operandDisplayName($iface->name, $lc),
            'extends' => $extends,
            'methods' => $methods,
        ];
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
        $implements = [];
        foreach ($class->implements as $ifaceOperand) {
            $ifaceLc = $this->operandLcName($ifaceOperand);
            if (null !== $ifaceLc) {
                $implements[] = $ifaceLc;
            }
        }
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $methods = $this->collectConcreteMethods($class->stmts->children);
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\TraitUse) {
                foreach ($member->traits as $traitOperand) {
                    $traitLc = $this->operandLcName($traitOperand);
                    if (null === $traitLc || !isset($this->traits[$traitLc])) {
                        continue;
                    }
                    foreach ($this->traits[$traitLc]['methods'] as $name => $_) {
                        $methods[$name] = true;
                    }
                }
            }
        }
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'abstract' => 0 !== ($class->flags & \PhpParser\Node\Stmt\Class_::MODIFIER_ABSTRACT),
            'extends' => $parentLc,
            'implements' => $implements,
            'methods' => $methods,
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
        foreach ($this->classes as $lc => $class) {
            if ($class['abstract']) {
                continue;
            }
            $provided = $this->classProvidedMethods($lc);
            $missing = $this->missingInterfaceMethods($class['implements'], $provided);
            if ([] === $missing) {
                continue;
            }
            $count = count($missing);
            $suffix = 1 === $count ? '' : 's';
            $list = implode(', ', array_map(
                static fn (array $pair): string => $pair[0].'::'.$pair[1],
                $missing
            ));
            throw new \CompileError(
                "Class {$class['display']} contains {$count} abstract method{$suffix} "
                ."and must therefore be declared abstract or implement the remaining methods ({$list})"
            );
        }
    }

    /**
     * @return array<string, true>
     */
    private function classProvidedMethods(string $classLc): array
    {
        $provided = [];
        $visited = [];
        $current = $classLc;
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->classes[$current])) {
                break;
            }
            foreach ($this->classes[$current]['methods'] as $name => $_) {
                $provided[$name] = true;
            }
            $current = $this->classes[$current]['extends'];
        }

        return $provided;
    }

    /**
     * @param list<string> $directInterfaces
     * @param array<string, true> $provided
     *
     * @return list<array{0: string, 1: string}>
     */
    private function missingInterfaceMethods(array $directInterfaces, array $provided): array
    {
        $required = [];
        $ifaceVisited = [];
        $queue = $directInterfaces;
        while ([] !== $queue) {
            $ifaceLc = array_shift($queue);
            if (isset($ifaceVisited[$ifaceLc])) {
                continue;
            }
            $ifaceVisited[$ifaceLc] = true;
            if (!isset($this->interfaces[$ifaceLc])) {
                continue;
            }
            $iface = $this->interfaces[$ifaceLc];
            foreach ($iface['methods'] as $methodLc => $_) {
                $key = $ifaceLc.'::'.$methodLc;
                if (!isset($required[$key])) {
                    $required[$key] = [$iface['display'], $methodLc];
                }
            }
            foreach ($iface['extends'] as $parentLc) {
                $queue[] = $parentLc;
            }
        }

        $missing = [];
        foreach ($required as $pair) {
            [$ifaceDisplay, $methodLc] = $pair;
            if (!isset($provided[$methodLc])) {
                $missing[] = [$ifaceDisplay, $methodLc];
            }
        }

        return $missing;
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
