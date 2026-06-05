<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PhpParser\Node\Stmt\Class_ as ClassNode;

/**
 * Compile-time check: typed class constant redeclarations must be covariant-compatible
 * with parent/interface/trait definitions (PHP 8.3, zend_inheritance.c do_inherit_constant_check, #5953, #5982, #5993).
 */
final class TypedClassConstInheritCheck
{
    /**
     * @var array<string, array{
     *     display: string,
     *     constants: array<string, array{display: string, type: ?TypeSig, private: bool}>,
     *     extends: ?string,
     *     implements: list<string>,
     *     ifaceExtends: list<string>,
     *     traitUses: list<string>
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
            } elseif ($child instanceof Op\Stmt\Interface_) {
                $this->collectInterface($child);
            } elseif ($child instanceof Op\Stmt\Trait_) {
                $this->collectTrait($child);
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
        $implements = [];
        foreach ($class->implements as $ifaceOperand) {
            $ifaceLc = $this->operandLcName($ifaceOperand);
            if (null !== $ifaceLc) {
                $implements[] = $ifaceLc;
            }
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'constants' => $this->collectConstants($class->stmts->children),
            'extends' => $parentLc,
            'implements' => $implements,
            'ifaceExtends' => [],
            'traitUses' => $this->collectTraitUses($class->stmts->children),
        ];
    }

    private function collectInterface(Op\Stmt\Interface_ $iface): void
    {
        $lc = $this->operandLcName($iface->name);
        if (null === $lc) {
            return;
        }
        $extends = [];
        foreach ($iface->extends as $parentOperand) {
            $parentLc = $this->operandLcName($parentOperand);
            if (null !== $parentLc) {
                $extends[] = $parentLc;
            }
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($iface->name, $lc),
            'constants' => $this->collectConstants($iface->stmts->children),
            'extends' => null,
            'implements' => [],
            'ifaceExtends' => $extends,
            'traitUses' => [],
        ];
    }

    private function collectTrait(Op\Stmt\Trait_ $trait): void
    {
        $lc = $this->operandLcName($trait->name);
        if (null === $lc) {
            return;
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($trait->name, $lc),
            'constants' => $this->collectConstants($trait->stmts->children),
            'extends' => null,
            'implements' => [],
            'ifaceExtends' => [],
            'traitUses' => $this->collectTraitUses($trait->stmts->children),
        ];
    }

    /**
     * @param list<Op> $members
     *
     * @return list<string>
     */
    private function collectTraitUses(array $members): array
    {
        $traits = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($member->traits as $traitOperand) {
                $traitLc = $this->operandLcName($traitOperand);
                if (null !== $traitLc) {
                    $traits[] = $traitLc;
                }
            }
        }

        return $traits;
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, array{display: string, type: ?TypeSig, private: bool}>
     */
    private function collectConstants(array $members): array
    {
        $constants = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if (property_exists($member, 'isEnumCase') && $member->isEnumCase) {
                continue;
            }
            $name = $this->staticNameFromOperand($member->name);
            if (null === $name) {
                continue;
            }
            $type = null;
            if (property_exists($member, 'declaredType') && null !== $member->declaredType) {
                $type = TypeSig::fromCfgType($member->declaredType);
            }
            $flags = property_exists($member, 'flags') ? (int) $member->flags : ClassNode::MODIFIER_PUBLIC;
            $constants[strtolower($name)] = [
                'display' => $name,
                'type' => $type,
                'private' => 0 !== ($flags & ClassNode::MODIFIER_PRIVATE),
            ];
        }

        return $constants;
    }

    private function verify(): void
    {
        foreach ($this->types as $lc => $type) {
            foreach ($type['constants'] as $constLc => $childConst) {
                foreach ($this->parentTypedConstantSources($type) as $parentSource) {
                    if (!isset($parentSource['constants'][$constLc])) {
                        continue;
                    }
                    $parentConst = $parentSource['constants'][$constLc];
                    if ($parentConst['private']) {
                        continue;
                    }
                    if (null === $parentConst['type']) {
                        continue;
                    }
                    if (InheritanceVariance::isCovariantTypeCompatible(
                        $parentConst['type'],
                        $childConst['type'],
                        $parentSource['lc'],
                        $lc,
                        fn (string $subtype, string $supertype): bool => $this->isClassSubtypeOf($subtype, $supertype),
                        fn (string $classLc, string $interfaceLc): bool => $this->classImplementsInterface($classLc, $interfaceLc)
                    )) {
                        continue;
                    }
                    throw new \CompileError(sprintf(
                        'Type of %s::%s must be compatible with %s::%s of type %s',
                        $type['display'],
                        $childConst['display'],
                        $parentSource['display'],
                        $parentConst['display'],
                        $parentConst['type']->format()
                    ));
                }
            }
        }
    }

    /**
     * Parent class + implemented / extended interfaces that may define inherited typed constants.
     *
     * @param array{display: string, constants: array<string, array{display: string, type: ?TypeSig, private: bool}>, extends: ?string, implements: list<string>, ifaceExtends: list<string>, traitUses: list<string>} $type
     *
     * @return list<array{lc: string, display: string, constants: array<string, array{display: string, type: ?TypeSig, private: bool}>}>
     */
    private function parentTypedConstantSources(array $type): array
    {
        $sources = [];
        $seen = [];
        $parentLc = $type['extends'];
        while (null !== $parentLc && '' !== $parentLc && !isset($seen[$parentLc])) {
            $seen[$parentLc] = true;
            if (!isset($this->types[$parentLc])) {
                break;
            }
            $sources[] = [
                'lc' => $parentLc,
                'display' => $this->types[$parentLc]['display'],
                'constants' => $this->types[$parentLc]['constants'],
            ];
            $parentLc = $this->types[$parentLc]['extends'];
        }
        foreach ($type['implements'] as $ifaceLc) {
            $sources = array_merge($sources, $this->interfaceConstantSources($ifaceLc, $seen));
        }
        foreach ($type['ifaceExtends'] as $ifaceLc) {
            $sources = array_merge($sources, $this->interfaceConstantSources($ifaceLc, $seen));
        }
        foreach ($type['traitUses'] as $traitLc) {
            $sources = array_merge($sources, $this->traitConstantSources($traitLc, $seen));
        }

        return $sources;
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<array{lc: string, display: string, constants: array<string, array{display: string, type: ?TypeSig, private: bool}>}>
     */
    private function traitConstantSources(string $traitLc, array &$seen): array
    {
        $sources = [];
        $queue = [$traitLc];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ('' === $current || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if (!isset($this->types[$current])) {
                continue;
            }
            $trait = $this->types[$current];
            $sources[] = [
                'lc' => $current,
                'display' => $trait['display'],
                'constants' => $trait['constants'],
            ];
            foreach ($trait['traitUses'] as $nestedTraitLc) {
                $queue[] = $nestedTraitLc;
            }
        }

        return $sources;
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<array{lc: string, display: string, constants: array<string, array{display: string, type: ?TypeSig, private: bool}>}>
     */
    private function interfaceConstantSources(string $ifaceLc, array &$seen): array
    {
        $sources = [];
        $queue = [$ifaceLc];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ('' === $current || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if (!isset($this->types[$current])) {
                continue;
            }
            $iface = $this->types[$current];
            $sources[] = [
                'lc' => $current,
                'display' => $iface['display'],
                'constants' => $iface['constants'],
            ];
            foreach ($iface['ifaceExtends'] as $parentIface) {
                $queue[] = $parentIface;
            }
        }

        return $sources;
    }

    private function isClassSubtypeOf(string $subtypeLc, string $supertypeLc): bool
    {
        if ($subtypeLc === $supertypeLc) {
            return true;
        }
        $current = $subtypeLc;
        $guard = 0;
        while (null !== ($parent = $this->types[$current]['extends'] ?? null)) {
            if (++$guard > 256) {
                return false;
            }
            if ($parent === $supertypeLc) {
                return true;
            }
            if (!isset($this->types[$parent])) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    private function classImplementsInterface(string $classLc, string $interfaceLc): bool
    {
        if ($classLc === $interfaceLc) {
            return true;
        }
        foreach ($this->types[$classLc]['implements'] ?? [] as $ifaceLc) {
            if ($this->interfaceExtendsOrEquals($ifaceLc, $interfaceLc)) {
                return true;
            }
        }
        $parent = $this->types[$classLc]['extends'] ?? null;
        if (null !== $parent) {
            return $this->classImplementsInterface($parent, $interfaceLc);
        }

        return false;
    }

    private function interfaceExtendsOrEquals(string $ifaceLc, string $targetLc): bool
    {
        if ($ifaceLc === $targetLc) {
            return true;
        }
        foreach ($this->types[$ifaceLc]['ifaceExtends'] ?? [] as $parentIface) {
            if ($this->interfaceExtendsOrEquals($parentIface, $targetLc)) {
                return true;
            }
        }

        return false;
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
