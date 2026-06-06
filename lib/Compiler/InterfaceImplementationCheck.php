<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Op\Expr\Param;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\MethodVisibility;

/**
 * Compile-time check: concrete classes must implement all interface methods (#3386, #3536)
 * and interface hooked properties (#6770).
 *
 * php-src: Zend/zend_inheritance.c — zend_do_implement_interface, zend_verify_abstract_class
 * php-src: Zend/zend_property_hooks.c — interface property hook obligations
 */
final class InterfaceImplementationCheck
{
    /** @var array<string, array{display: string, extends: list<string>, methods: array<string, true>, properties: array<string, string>}> */
    private array $interfaces = [];

    /** @var array<string, array{display: string, abstract: bool, extends: ?string, implements: list<string>, methods: array<string, true>, abstractMethods: array<string, true>, properties: array<string, true>}> */
    private array $classes = [];

    /** @var array<string, array{display: string, methods: array<string, true>}> */
    private array $traits = [];

    /** @var array<string, array<string, array<string, mixed>>> lcClass => prop => meta */
    private array $propertyHookRegistry;

    /**
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    public static function validate(Script $script, array $propertyHookRegistry = []): void
    {
        $check = new self($propertyHookRegistry);
        $check->collect($script);
        $check->verify();
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    private function __construct(array $propertyHookRegistry)
    {
        $this->propertyHookRegistry = $propertyHookRegistry;
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
        $properties = [];
        foreach ($iface->stmts->children as $member) {
            if ($member instanceof Op\Stmt\ClassMethod) {
                $methods[strtolower($member->func->name)] = true;
            } elseif ($member instanceof Op\Stmt\Property) {
                $propName = $this->propertyDisplayName($member->name);
                if (!$this->interfacePropertyHasHooks($lc, $propName)) {
                    throw new \CompileError('Interfaces may not include properties');
                }
                $properties[strtolower($propName)] = $propName;
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
            'properties' => $properties,
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
        $properties = $this->collectDeclaredProperties($class->stmts->children);
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
            'abstractMethods' => $this->collectAbstractMethods($class->stmts->children),
            'properties' => $properties,
        ];
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectDeclaredProperties(array $members): array
    {
        $properties = [];
        foreach ($members as $member) {
            if ($member instanceof Op\Stmt\Property) {
                $properties[strtolower($this->propertyDisplayName($member->name))] = true;
                continue;
            }
            if (!$member instanceof Op\Stmt\ClassMethod || !$this->isConstructor($member)) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!$this->isPromotedParam($param)) {
                    continue;
                }
                $properties[strtolower($this->propertyDisplayName($param->name))] = true;
            }
        }

        return $properties;
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

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectAbstractMethods(array $members): array
    {
        $methods = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if (!$this->methodHasBody($member)) {
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
            $providedMethods = $this->classProvidedMethods($lc);
            $providedProperties = $this->classProvidedProperties($lc);
            $missing = array_merge(
                $this->missingInterfaceMethods($class['implements'], $providedMethods),
                $this->missingInterfaceProperties($class['implements'], $providedProperties),
                $this->missingParentAbstractMethods($class['extends'], $providedMethods),
                $this->missingAbstractPropertyHooks($lc, $class)
            );
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
     * @return array<string, true>
     */
    private function classProvidedProperties(string $classLc): array
    {
        $provided = [];
        $visited = [];
        $current = $classLc;
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->classes[$current])) {
                break;
            }
            foreach ($this->classes[$current]['properties'] as $name => $_) {
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

    /**
     * @param list<string> $directInterfaces
     * @param array<string, true> $provided
     *
     * @return list<array{0: string, 1: string}>
     */
    private function missingInterfaceProperties(array $directInterfaces, array $provided): array
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
            foreach ($iface['properties'] as $propLc => $propDisplay) {
                $key = $ifaceLc.'::'.$propLc;
                if (!isset($required[$key])) {
                    $required[$key] = [$iface['display'], $propDisplay, $ifaceLc, $propDisplay];
                }
            }
            foreach ($iface['extends'] as $parentLc) {
                $queue[] = $parentLc;
            }
        }

        $missing = [];
        foreach ($required as $pair) {
            [$ifaceDisplay, $propDisplay, $ifaceLc, $propName] = $pair;
            $propLc = strtolower($propDisplay);
            if (isset($provided[$propLc])) {
                continue;
            }
            foreach ($this->missingPropertyHookObligations($ifaceLc, $propName) as $hookLabel) {
                $missing[] = [$ifaceDisplay, $hookLabel];
            }
        }

        return $missing;
    }

    /**
     * Concrete classes must implement abstract property hooks declared on self or abstract parents (#6763).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function missingAbstractPropertyHooks(string $classLc, array $class): array
    {
        $provided = $this->classProvidedPropertyHooks($classLc);
        $missing = [];
        $seen = [];
        foreach ($this->collectAbstractPropertyHookRequirements($classLc) as [$ownerDisplay, $propDisplay, $hookKind]) {
            $propLc = strtolower($propDisplay);
            if ($this->propertyHookKindProvided($provided[$propLc] ?? [], $hookKind)) {
                continue;
            }
            $label = '$'.$propDisplay.'::'.$hookKind;
            $key = $ownerDisplay.'::'.$label;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $missing[] = [$ownerDisplay, $label];
        }

        return $missing;
    }

    /**
     * @return array<string, array<string, true>> lcProp => hook kind => true
     */
    private function classProvidedPropertyHooks(string $classLc): array
    {
        $provided = [];
        $visited = [];
        $current = $classLc;
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            foreach ($this->propertyHookRegistry[$current] ?? [] as $prop => $meta) {
                $propLc = strtolower($prop);
                if (!isset($provided[$propLc])) {
                    $provided[$propLc] = [];
                }
                foreach (['get', 'set', 'unset'] as $kind) {
                    if (isset($meta[$kind])) {
                        $provided[$propLc][$kind] = true;
                    }
                }
            }
            if (!isset($this->classes[$current])) {
                break;
            }
            $current = $this->classes[$current]['extends'];
        }

        return $provided;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> owner display, prop display, hook kind
     */
    private function collectAbstractPropertyHookRequirements(string $classLc): array
    {
        $requirements = [];
        $visited = [];
        $current = $classLc;
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->classes[$current])) {
                break;
            }
            $decl = $this->classes[$current];
            foreach ($this->propertyHookRegistry[$current] ?? [] as $prop => $meta) {
                $ownerDisplay = $decl['display'];
                foreach ($this->requiredPropertyHookKinds($meta) as $hookKind) {
                    $requirements[] = [$ownerDisplay, $prop, $hookKind];
                }
            }
            $current = $decl['extends'];
        }

        return $requirements;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return list<string>
     */
    private function requiredPropertyHookKinds(array $meta): array
    {
        $kinds = [];
        if (!empty($meta['requiresGet'])) {
            $kinds[] = 'get';
        }
        if (!empty($meta['requiresSet'])) {
            $kinds[] = 'set';
        }
        if (!empty($meta['requiresUnset'])) {
            $kinds[] = 'unset';
        }

        return $kinds;
    }

    /**
     * @param array<string, true> $provided
     */
    private function propertyHookKindProvided(array $provided, string $kind): bool
    {
        return isset($provided[$kind]);
    }

    /**
     * Interface members with hook syntax are lowered to plain properties plus registry metadata (#6620).
     * Plain typed properties without hooks remain illegal (#6902, zend_compile.c).
     */
    private function interfacePropertyHasHooks(string $ifaceLc, string $propName): bool
    {
        return isset($this->propertyHookRegistry[$ifaceLc][$propName])
            || isset($this->propertyHookRegistry[$ifaceLc][strtolower($propName)]);
    }

    /**
     * @return list<string> labels like $x::get for php-src abstract-method diagnostics
     */
    private function missingPropertyHookObligations(string $ifaceLc, string $propName): array
    {
        $meta = $this->propertyHookRegistry[$ifaceLc][$propName]
            ?? $this->propertyHookRegistry[$ifaceLc][strtolower($propName)]
            ?? [];
        $obligations = [];
        if (!empty($meta['requiresGet'])) {
            $obligations[] = '$'.$propName.'::get';
        }
        if (!empty($meta['requiresSet'])) {
            $obligations[] = '$'.$propName.'::set';
        }
        if (!empty($meta['requiresUnset'])) {
            $obligations[] = '$'.$propName.'::unset';
        }
        if ([] !== $obligations) {
            return $obligations;
        }

        return ['$'.$propName];
    }

    /**
     * @param array<string, true> $provided
     *
     * @return list<array{0: string, 1: string}>
     */
    private function missingParentAbstractMethods(?string $parentLc, array $provided): array
    {
        $missing = [];
        $visited = [];
        $current = $parentLc;
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->classes[$current])) {
                break;
            }
            $parent = $this->classes[$current];
            foreach ($parent['abstractMethods'] as $methodLc => $_) {
                if (!isset($provided[$methodLc])) {
                    $missing[] = [$parent['display'], $methodLc];
                }
            }
            $current = $parent['extends'];
        }

        return $missing;
    }

    private function isConstructor(Op\Stmt\ClassMethod $method): bool
    {
        $name = $method->func->name ?? null;
        if (!is_string($name)) {
            return false;
        }

        return '__construct' === strtolower($name);
    }

    private function isPromotedParam(Param $param): bool
    {
        return 0 !== (MethodVisibility::mask($param->promotionFlags));
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
