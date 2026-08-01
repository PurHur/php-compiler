<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\VM\ClassAbstract;

/**
 * Compile-time check: enums must implement declared abstract methods (#6618, #6887, #7353).
 *
 * php-src: Zend/zend_compile.c — enum abstract method validation;
 * Zend/zend_enum.c — enum method table / abstract enforcement.
 *
 * Note: `abstract enum` itself is not Zend syntax (#26519); this check covers abstract methods
 * on ordinary enums (traits / interfaces) and leftover abstract-enum CFG if any.
 */
final class EnumAbstractMethodCompileCheck
{
    /** @var array<string, array{abstract: array<string, string>, concrete: array<string, true>}> */
    private array $traits = [];

    /** @var array<string, array{display: string, abstract: bool, abstractMethods: array<string, string>}> */
    private array $enums = [];

    /** @var array<string, array{display: string, extends: list<string>, methods: array<string, true>}> */
    private array $interfaces = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collectTraits($script);
        $check->collectInterfaces($script);
        $check->collectEnums($script);
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Enum_) {
                $check->validateEnum($child);
            }
        }
    }

    private function collectTraits(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Trait_) {
                continue;
            }
            $lc = $this->operandLcName($child->name);
            if (null === $lc) {
                continue;
            }
            $this->traits[$lc] = [
                'abstract' => $this->collectAbstractMethodNames($child->stmts->children),
                'concrete' => $this->collectConcreteMethodNames($child->stmts->children),
            ];
        }
    }

    private function collectInterfaces(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Interface_) {
                continue;
            }
            $lc = $this->operandLcName($child->name);
            if (null === $lc) {
                continue;
            }
            $methods = [];
            foreach ($child->stmts->children as $member) {
                if ($member instanceof Op\Stmt\ClassMethod) {
                    $name = $member->func->name;
                    if (is_string($name)) {
                        $methods[strtolower($name)] = true;
                    }
                }
            }
            $extends = [];
            foreach ($child->extends as $parentOperand) {
                $parentLc = $this->operandLcName($parentOperand);
                if (null !== $parentLc) {
                    $extends[] = $parentLc;
                }
            }
            $this->interfaces[$lc] = [
                'display' => $this->operandDisplayName($child->name, $lc),
                'extends' => $extends,
                'methods' => $methods,
            ];
        }
    }

    private function collectEnums(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Enum_) {
                continue;
            }
            $lc = $this->operandLcName($child->name);
            if (null === $lc) {
                continue;
            }
            $this->enums[$lc] = [
                'display' => $this->operandDisplayName($child->name, $lc),
                'abstract' => ClassAbstract::fromClassFlags($child->flags ?? 0),
                'abstractMethods' => $this->collectAbstractMethodNames($child->stmts->children),
            ];
        }
    }

    private function validateEnum(Op\Stmt\Enum_ $enum): void
    {
        if (ClassAbstract::fromClassFlags($enum->flags ?? 0)) {
            return;
        }

        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        $abstractMethods = $this->collectAbstractMethodNames($enum->stmts->children);
        $concrete = $this->collectConcreteMethodNames($enum->stmts->children);

        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($member->traits as $traitOperand) {
                $traitLc = $this->operandLcName($traitOperand);
                if (null === $traitLc || !isset($this->traits[$traitLc])) {
                    continue;
                }
                foreach ($this->traits[$traitLc]['abstract'] as $methodLc => $methodName) {
                    if (!isset($abstractMethods[$methodLc])) {
                        $abstractMethods[$methodLc] = $methodName;
                    }
                }
                foreach ($this->traits[$traitLc]['concrete'] as $methodLc => $_) {
                    $concrete[$methodLc] = true;
                }
            }
        }

        $implementedInterfaces = [];
        foreach ($enum->implements as $implementedOperand) {
            $implementedLc = $this->operandLcName($implementedOperand);
            if (null === $implementedLc) {
                continue;
            }
            if (isset($this->enums[$implementedLc])) {
                $implemented = $this->enums[$implementedLc];
                if (!$implemented['abstract']) {
                    continue;
                }
                foreach ($implemented['abstractMethods'] as $methodLc => $methodName) {
                    if (!isset($abstractMethods[$methodLc])) {
                        $abstractMethods[$methodLc] = $methodName;
                    }
                }
                continue;
            }
            if (isset($this->interfaces[$implementedLc])) {
                $implementedInterfaces[] = $implementedLc;
            }
        }

        /** @var list<array{0: string, 1: string}> owner display, method display */
        $missing = [];
        foreach ($abstractMethods as $methodLc => $methodName) {
            if (!isset($concrete[$methodLc])) {
                $missing[] = [$enumDisplay, $methodName];
            }
        }
        foreach ($this->missingInterfaceMethods($implementedInterfaces, $concrete) as [$ifaceDisplay, $methodDisplay]) {
            $missing[] = [$ifaceDisplay, $methodDisplay];
        }
        if ([] === $missing) {
            return;
        }

        $count = count($missing);
        $suffix = 1 === $count ? '' : 's';
        $list = implode(', ', array_map(
            static fn (array $pair): string => $pair[0].'::'.$pair[1],
            $missing
        ));
        throw new CompileFatal(
            $enum->getFile(),
            max(1, $enum->getLine()),
            "Enum {$enumDisplay} must implement {$count} abstract private method{$suffix} ({$list})"
        );
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, string> lowercase name => display name
     */
    private function collectAbstractMethodNames(array $members): array
    {
        $methods = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if ($this->methodHasBody($member)) {
                continue;
            }
            $name = $member->func->name;
            if (!is_string($name)) {
                continue;
            }
            $methods[strtolower($name)] = $name;
        }

        return $methods;
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectConcreteMethodNames(array $members): array
    {
        $methods = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if (!$this->methodHasBody($member)) {
                continue;
            }
            $name = $member->func->name;
            if (!is_string($name)) {
                continue;
            }
            $methods[strtolower($name)] = true;
        }

        return $methods;
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
            if (isset($provided[$methodLc])) {
                continue;
            }
            $missing[] = [$ifaceDisplay, $methodLc];
        }

        return $missing;
    }

    private function methodHasBody(Op\Stmt\ClassMethod $method): bool
    {
        $cfg = $method->func->cfg;
        if (null === $cfg) {
            return false;
        }

        return [] !== $cfg->children;
    }

    private function operandLcName(Operand $op): ?string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $fallback;
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
