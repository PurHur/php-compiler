<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op\Stmt\ClassMethod;

/**
 * Compile-time map of declared class/interface/trait methods (#3211).
 */
final class ClassCompileRegistry
{
    /** @var array<string, list<string>> lcName => method names (lowercase) */
    private array $methods = [];

    /** @var array<string, ?string> lcName => parent lc name */
    private array $parents = [];

    /** @var array<string, list<string>> lcName => extended interface lc names */
    private array $interfaces = [];

    public function registerClass(string $name, ?string $parentLc, array $interfaceLcs, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->parents[$lc] = $parentLc;
        $this->interfaces[$lc] = $interfaceLcs;
        $this->methods[$lc] = self::methodNamesFromStmts($stmts);
    }

    public function registerInterface(string $name, array $extendsLcs, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->parents[$lc] = null;
        $this->interfaces[$lc] = $extendsLcs;
        $this->methods[$lc] = self::methodNamesFromStmts($stmts);
    }

    public function registerTrait(string $name, CfgBlock $stmts): void
    {
        $lc = self::lc($name);
        $this->parents[$lc] = null;
        $this->interfaces[$lc] = [];
        $this->methods[$lc] = self::methodNamesFromStmts($stmts);
    }

    public function hasOverridableMethod(?string $parentLc, array $interfaceLcs, string $methodLc): bool
    {
        if (null !== $parentLc && '' !== $parentLc && $this->hasMethodInClassChain($parentLc, $methodLc)) {
            return true;
        }

        foreach ($interfaceLcs as $ifaceLc) {
            if ($this->hasMethodInInterfaceChain($ifaceLc, $methodLc)) {
                return true;
            }
        }

        return false;
    }

    private function hasMethodInClassChain(string $classLc, string $methodLc): bool
    {
        $visited = [];
        while ('' !== $classLc && !isset($visited[$classLc])) {
            $visited[$classLc] = true;
            if (isset($this->methods[$classLc]) && in_array($methodLc, $this->methods[$classLc], true)) {
                return true;
            }
            $parent = $this->parents[$classLc] ?? null;
            if (null === $parent || '' === $parent) {
                break;
            }
            $classLc = $parent;
        }

        return false;
    }

    private function hasMethodInInterfaceChain(string $ifaceLc, string $methodLc): bool
    {
        $visited = [];
        $queue = [$ifaceLc];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ('' === $current || isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if (isset($this->methods[$current]) && in_array($methodLc, $this->methods[$current], true)) {
                return true;
            }
            foreach ($this->interfaces[$current] ?? [] as $parentIface) {
                $queue[] = $parentIface;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function methodNamesFromStmts(CfgBlock $stmts): array
    {
        $names = [];
        foreach ($stmts->children as $child) {
            if ($child instanceof ClassMethod) {
                $names[] = strtolower($child->func->name);
            }
        }

        return $names;
    }

    private static function lc(string $name): string
    {
        return strtolower(ltrim($name, '\\'));
    }
}
