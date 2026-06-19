<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Op\Stmt\ClassMethod;
use PHPCfg\Op\Terminal\Const_ as CfgConst;
use PhpParser\Node\Stmt\Class_ as ClassNode;

/**
 * Compile-time map of declared class/interface/trait methods (#3211, #4529).
 */
final class ClassCompileRegistry
{
    /** @var array<string, array<string, MethodSig>> lcName => method lc => signature */
    private array $methods = [];

    /** @var array<string, array<string, array{private: bool, ownerLc: string}>> lcName => const lc => visibility */
    private array $constants = [];

    /** @var array<string, string> lcName => display name */
    private array $displayNames = [];

    /** @var array<string, ?string> lcName => parent lc name */
    private array $parents = [];

    /** @var array<string, list<string>> lcName => extended interface lc names */
    private array $interfaces = [];

    /** @var array<string, true> registered trait lc names (#4973) */
    private array $traits = [];

    /** @var array<string, true> registered interface lc names (#7042) */
    private array $registeredInterfaces = [];

    /** @var array<string, CfgBlock> trait lc name => body stmts (#6761) */
    private array $traitStmts = [];

    public function registerClass(string $name, ?string $parentLc, array $interfaceLcs, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->displayNames[$lc] = ltrim($name, '\\');
        $this->parents[$lc] = $parentLc;
        $this->interfaces[$lc] = $interfaceLcs;
        $ownMethods = self::methodSigsFromStmts($stmts, $lc);
        $traitMethods = TraitComposedMethodResolver::resolve($stmts, $this);
        $merged = [];
        foreach ($traitMethods as $methodLc => $entry) {
            $merged[$methodLc] = $entry['sig'];
        }
        foreach ($ownMethods as $methodLc => $sig) {
            $merged[$methodLc] = $sig;
        }
        $this->methods[$lc] = $merged;
        $this->constants[$lc] = self::constInfoFromStmts($stmts, $lc);
    }

    public function registerInterface(string $name, array $extendsLcs, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->displayNames[$lc] = ltrim($name, '\\');
        $this->parents[$lc] = null;
        $this->interfaces[$lc] = $extendsLcs;
        $this->registeredInterfaces[$lc] = true;
        $this->methods[$lc] = self::methodSigsFromStmts($stmts, $lc);
        $this->constants[$lc] = self::constInfoFromStmts($stmts, $lc);
    }

    public function registerTrait(string $name, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->displayNames[$lc] = ltrim($name, '\\');
        $this->parents[$lc] = null;
        $this->interfaces[$lc] = [];
        $this->traits[$lc] = true;
        $this->traitStmts[$lc] = $stmts;
        $this->methods[$lc] = self::methodSigsFromStmts($stmts, $lc);
        $this->constants[$lc] = self::constInfoFromStmts($stmts, $lc);
    }

    public function getTraitStmts(string $lcName): ?CfgBlock
    {
        $lc = self::lc($lcName);

        return $this->traitStmts[$lc] ?? null;
    }

    public function traitDisplayName(string $lcName): string
    {
        $lc = self::lc($lcName);

        return $this->displayNames[$lc] ?? $lc;
    }

    public function isTrait(string $lcName): bool
    {
        return isset($this->traits[self::lc($lcName)]);
    }

    public function isInterface(string $lcName): bool
    {
        return isset($this->registeredInterfaces[self::lc($lcName)]);
    }

    public function hasOverridableMethod(
        ?string $parentLc,
        array $interfaceLcs,
        string $methodLc,
        string $childClassLc
    ): bool {
        if (null !== $parentLc && '' !== $parentLc && $this->hasMethodInClassChain($parentLc, $methodLc, $childClassLc)) {
            return true;
        }

        foreach ($interfaceLcs as $ifaceLc) {
            if ($this->hasMethodInInterfaceChain($ifaceLc, $methodLc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{sig: MethodSig, ownerLc: string, ownerDisplay: string}|null
     */
    public function findOverriddenMethod(
        ?string $parentLc,
        array $interfaceLcs,
        string $methodLc,
        string $childClassLc
    ): ?array {
        if (null !== $parentLc && '' !== $parentLc) {
            $found = $this->findMethodInClassChain($parentLc, $methodLc, $childClassLc);
            if (null !== $found) {
                return $found;
            }
        }

        foreach ($interfaceLcs as $ifaceLc) {
            $found = $this->findMethodInInterfaceChain($ifaceLc, $methodLc);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    public function hasOverridableConstant(
        ?string $parentLc,
        array $interfaceLcs,
        string $constLc,
        string $childClassLc
    ): bool {
        if (null !== $parentLc && '' !== $parentLc && $this->hasConstantInClassChain($parentLc, $constLc, $childClassLc)) {
            return true;
        }

        foreach ($interfaceLcs as $ifaceLc) {
            if ($this->hasConstantInInterfaceChain($ifaceLc, $constLc)) {
                return true;
            }
        }

        return false;
    }

    public function hasConstantInTrait(string $traitLc, string $constLc): bool
    {
        return isset($this->constants[self::lc($traitLc)][$constLc]);
    }

    public function isClassSubtypeOf(string $subtypeLc, string $supertypeLc): bool
    {
        if ($subtypeLc === $supertypeLc) {
            return true;
        }
        $current = $subtypeLc;
        $guard = 0;
        while (null !== ($parent = $this->parents[$current] ?? null) && '' !== $parent) {
            if (++$guard > 256) {
                return false;
            }
            if ($parent === $supertypeLc) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    public function classImplementsInterface(string $classLc, string $interfaceLc): bool
    {
        if ($classLc === $interfaceLc) {
            return true;
        }
        foreach ($this->interfaces[$classLc] ?? [] as $ifaceLc) {
            if ($this->interfaceExtendsOrEquals($ifaceLc, $interfaceLc)) {
                return true;
            }
        }
        $parent = $this->parents[$classLc] ?? null;
        if (null !== $parent && '' !== $parent) {
            return $this->classImplementsInterface($parent, $interfaceLc);
        }

        return false;
    }

    private function interfaceExtendsOrEquals(string $ifaceLc, string $targetLc): bool
    {
        if ($ifaceLc === $targetLc) {
            return true;
        }
        foreach ($this->interfaces[$ifaceLc] ?? [] as $parentIface) {
            if ($this->interfaceExtendsOrEquals($parentIface, $targetLc)) {
                return true;
            }
        }

        return false;
    }

    private function hasMethodInClassChain(string $classLc, string $methodLc, string $childClassLc): bool
    {
        return null !== $this->findMethodInClassChain($classLc, $methodLc, $childClassLc);
    }

    /**
     * @return array{sig: MethodSig, ownerLc: string, ownerDisplay: string}|null
     */
    private function findMethodInClassChain(string $classLc, string $methodLc, string $childClassLc): ?array
    {
        $visited = [];
        while ('' !== $classLc && !isset($visited[$classLc])) {
            $visited[$classLc] = true;
            if (isset($this->methods[$classLc][$methodLc])) {
                $sig = $this->methods[$classLc][$methodLc];
                if (!$sig->isVisibleForOverrideFrom($childClassLc)) {
                    $parent = $this->parents[$classLc] ?? null;
                    if (null === $parent || '' === $parent) {
                        break;
                    }
                    $classLc = $parent;

                    continue;
                }

                return [
                    'sig' => $sig,
                    'ownerLc' => $classLc,
                    'ownerDisplay' => $this->displayNames[$classLc] ?? $classLc,
                ];
            }
            foreach ($this->interfaces[$classLc] ?? [] as $ifaceLc) {
                $found = $this->findMethodInInterfaceChain($ifaceLc, $methodLc);
                if (null !== $found) {
                    return $found;
                }
            }
            $parent = $this->parents[$classLc] ?? null;
            if (null === $parent || '' === $parent) {
                break;
            }
            $classLc = $parent;
        }

        return null;
    }

    private function hasMethodInInterfaceChain(string $ifaceLc, string $methodLc): bool
    {
        return null !== $this->findMethodInInterfaceChain($ifaceLc, $methodLc);
    }

    /**
     * @return array{sig: MethodSig, ownerLc: string, ownerDisplay: string}|null
     */
    private function findMethodInInterfaceChain(string $ifaceLc, string $methodLc): ?array
    {
        $visited = [];
        $queue = [$ifaceLc];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ('' === $current || isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if (isset($this->methods[$current][$methodLc])) {
                return [
                    'sig' => $this->methods[$current][$methodLc],
                    'ownerLc' => $current,
                    'ownerDisplay' => $this->displayNames[$current] ?? $current,
                ];
            }
            foreach ($this->interfaces[$current] ?? [] as $parentIface) {
                $queue[] = $parentIface;
            }
        }

        return null;
    }

    private function hasConstantInClassChain(string $classLc, string $constLc, string $childClassLc): bool
    {
        return null !== $this->findConstantInClassChain($classLc, $constLc, $childClassLc);
    }

    private function hasConstantInInterfaceChain(string $ifaceLc, string $constLc): bool
    {
        return null !== $this->findConstantInInterfaceChain($ifaceLc, $constLc);
    }

    /**
     * @return array{private: bool, ownerLc: string}|null
     */
    private function findConstantInClassChain(string $classLc, string $constLc, string $childClassLc): ?array
    {
        $visited = [];
        while ('' !== $classLc && !isset($visited[$classLc])) {
            $visited[$classLc] = true;
            if (isset($this->constants[$classLc][$constLc])) {
                $info = $this->constants[$classLc][$constLc];
                if (!$this->isConstantVisibleForOverride($info, $childClassLc)) {
                    $parent = $this->parents[$classLc] ?? null;
                    if (null === $parent || '' === $parent) {
                        break;
                    }
                    $classLc = $parent;

                    continue;
                }

                return $info;
            }
            foreach ($this->interfaces[$classLc] ?? [] as $ifaceLc) {
                $found = $this->findConstantInInterfaceChain($ifaceLc, $constLc);
                if (null !== $found) {
                    return $found;
                }
            }
            $parent = $this->parents[$classLc] ?? null;
            if (null === $parent || '' === $parent) {
                break;
            }
            $classLc = $parent;
        }

        return null;
    }

    /**
     * @return array{private: bool, ownerLc: string}|null
     */
    private function findConstantInInterfaceChain(string $ifaceLc, string $constLc): ?array
    {
        $visited = [];
        $queue = [$ifaceLc];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ('' === $current || isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if (isset($this->constants[$current][$constLc])) {
                return $this->constants[$current][$constLc];
            }
            foreach ($this->interfaces[$current] ?? [] as $parentIface) {
                $queue[] = $parentIface;
            }
        }

        return null;
    }

    /**
     * @param array{private: bool, ownerLc: string} $info
     */
    private function isConstantVisibleForOverride(array $info, string $childClassLc): bool
    {
        if ($info['private']) {
            return $info['ownerLc'] === $childClassLc;
        }

        return true;
    }

    /**
     * @return array<string, array{private: bool, ownerLc: string}>
     */
    private static function constInfoFromStmts(CfgBlock $stmts, string $ownerLc): array
    {
        $constants = [];
        foreach ($stmts->children as $child) {
            if (!$child instanceof CfgConst) {
                continue;
            }
            if (property_exists($child, 'isEnumCase') && $child->isEnumCase) {
                continue;
            }
            $name = self::constNameFromOperand($child->name);
            if (null === $name) {
                continue;
            }
            $flags = property_exists($child, 'flags')
                ? (int) $child->flags
                : ClassNode::MODIFIER_PUBLIC;
            $constants[strtolower($name)] = [
                'private' => 0 !== ($flags & ClassNode::MODIFIER_PRIVATE),
                'ownerLc' => $ownerLc,
            ];
        }

        return $constants;
    }

    private static function constNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return self::constNameFromOperand($op->name);
        }

        return null;
    }

    /**
     * @return array<string, MethodSig>
     */
    private static function methodSigsFromStmts(CfgBlock $stmts, string $ownerLc): array
    {
        $methods = [];
        foreach ($stmts->children as $child) {
            if ($child instanceof ClassMethod) {
                $name = strtolower($child->func->name);
                $methods[$name] = MethodSig::fromFunc($child->func, $ownerLc);
            }
        }

        return $methods;
    }

    private static function lc(string $name): string
    {
        return strtolower(ltrim($name, '\\'));
    }
}
