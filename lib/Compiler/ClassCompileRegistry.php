<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op\Stmt\ClassMethod;

/**
 * Compile-time map of declared class/interface/trait methods (#3211, #4529).
 */
final class ClassCompileRegistry
{
    /** @var array<string, array<string, MethodSig>> lcName => method lc => signature */
    private array $methods = [];

    /** @var array<string, string> lcName => display name */
    private array $displayNames = [];

    /** @var array<string, ?string> lcName => parent lc name */
    private array $parents = [];

    /** @var array<string, list<string>> lcName => extended interface lc names */
    private array $interfaces = [];

    /** @var array<string, true> registered trait lc names (#4973) */
    private array $traits = [];

    public function registerClass(string $name, ?string $parentLc, array $interfaceLcs, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->displayNames[$lc] = ltrim($name, '\\');
        $this->parents[$lc] = $parentLc;
        $this->interfaces[$lc] = $interfaceLcs;
        $this->methods[$lc] = self::methodSigsFromStmts($stmts, $lc);
    }

    public function registerInterface(string $name, array $extendsLcs, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->displayNames[$lc] = ltrim($name, '\\');
        $this->parents[$lc] = null;
        $this->interfaces[$lc] = $extendsLcs;
        $this->methods[$lc] = self::methodSigsFromStmts($stmts, $lc);
    }

    public function registerTrait(string $name, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->displayNames[$lc] = ltrim($name, '\\');
        $this->parents[$lc] = null;
        $this->interfaces[$lc] = [];
        $this->traits[$lc] = true;
        $this->methods[$lc] = self::methodSigsFromStmts($stmts, $lc);
    }

    public function isTrait(string $lcName): bool
    {
        return isset($this->traits[self::lc($lcName)]);
    }

    /**
     * @param list<string> $traitLcs traits composed into the class (#5550)
     */
    public function hasOverridableMethod(?string $parentLc, array $interfaceLcs, array $traitLcs, string $methodLc): bool
    {
        if (null !== $parentLc && '' !== $parentLc && $this->hasMethodInClassChain($parentLc, $methodLc)) {
            return true;
        }

        foreach ($interfaceLcs as $ifaceLc) {
            if ($this->hasMethodInInterfaceChain($ifaceLc, $methodLc)) {
                return true;
            }
        }

        foreach ($traitLcs as $traitLc) {
            if (isset($this->methods[$traitLc][$methodLc])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{sig: MethodSig, ownerLc: string, ownerDisplay: string}|null
     */
    /**
     * @param list<string> $traitLcs traits composed into the class (#5550)
     */
    public function findOverriddenMethod(?string $parentLc, array $interfaceLcs, array $traitLcs, string $methodLc): ?array
    {
        if (null !== $parentLc && '' !== $parentLc) {
            $found = $this->findMethodInClassChain($parentLc, $methodLc);
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

        foreach ($traitLcs as $traitLc) {
            if (isset($this->methods[$traitLc][$methodLc])) {
                return [
                    'sig' => $this->methods[$traitLc][$methodLc],
                    'ownerLc' => $traitLc,
                    'ownerDisplay' => $this->displayNames[$traitLc] ?? $traitLc,
                ];
            }
        }

        return null;
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

    private function hasMethodInClassChain(string $classLc, string $methodLc): bool
    {
        return null !== $this->findMethodInClassChain($classLc, $methodLc);
    }

    /**
     * @return array{sig: MethodSig, ownerLc: string, ownerDisplay: string}|null
     */
    private function findMethodInClassChain(string $classLc, string $methodLc): ?array
    {
        $visited = [];
        while ('' !== $classLc && !isset($visited[$classLc])) {
            $visited[$classLc] = true;
            if (isset($this->methods[$classLc][$methodLc])) {
                return [
                    'sig' => $this->methods[$classLc][$methodLc],
                    'ownerLc' => $classLc,
                    'ownerDisplay' => $this->displayNames[$classLc] ?? $classLc,
                ];
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
