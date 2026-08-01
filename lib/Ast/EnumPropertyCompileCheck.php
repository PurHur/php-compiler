<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Param;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Compile-time check: enums cannot declare instance properties (#6005, #26558).
 *
 * php-cfg drops Property nodes from enum bodies; validate on php-parser AST before CFG.
 * Also reject enums that `use` traits which introduce properties (direct or nested).
 *
 * php-src: Zend/zend_compile.c — zend_compile_enum; Zend/zend_traits.c — property import
 */
final class EnumPropertyCompileCheck extends NodeVisitorAbstract
{
    /**
     * Trait FQCN (lowercase) => whether that trait transitively declares properties.
     *
     * @var array<string, bool>
     */
    private array $traitHasProperties = [];

    public static function messageFor(string $enumName): string
    {
        return 'Enum ' . $enumName . ' cannot include properties';
    }

    public function beforeTraverse(array $nodes)
    {
        $this->traitHasProperties = $this->resolveTraitPropertyMap($nodes);

        return null;
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Enum_) {
            return null;
        }

        $enumName = $node->name->toString();
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Property) {
                $this->fatal($enumName, $stmt);
            }
            if ($stmt instanceof ClassMethod && $this->methodHasPromotedProperty($stmt)) {
                $this->fatal($enumName, $stmt);
            }
            if ($stmt instanceof TraitUse && $this->traitUseImportsProperties($stmt)) {
                $this->fatal($enumName, $stmt);
            }
        }

        return null;
    }

    private function traitUseImportsProperties(TraitUse $use): bool
    {
        foreach ($use->traits as $traitName) {
            $lc = strtolower($traitName->toString());
            if (!empty($this->traitHasProperties[$lc])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Node> $nodes
     *
     * @return array<string, bool>
     */
    private function resolveTraitPropertyMap(array $nodes): array
    {
        /** @var array<string, array{hasProp: bool, uses: list<string>}> */
        $traits = [];
        $this->collectTraits($nodes, $traits);

        $resolved = [];
        foreach (array_keys($traits) as $lc) {
            $resolved[$lc] = $this->traitTransitivelyHasProperties($lc, $traits, [], $resolved);
        }

        return $resolved;
    }

    /**
     * @param list<Node> $nodes
     * @param array<string, array{hasProp: bool, uses: list<string>}> $traits
     */
    private function collectTraits(array $nodes, array &$traits): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $this->collectTraits($node->stmts, $traits);
                continue;
            }
            if (!$node instanceof Trait_) {
                continue;
            }

            $fqcn = null !== $node->namespacedName
                ? $node->namespacedName->toString()
                : $node->name->toString();
            $lc = strtolower(ltrim($fqcn, '\\'));
            $hasProp = false;
            $uses = [];
            foreach ($node->stmts as $member) {
                if ($member instanceof Property) {
                    $hasProp = true;
                }
                if ($member instanceof ClassMethod && $this->methodHasPromotedProperty($member)) {
                    $hasProp = true;
                }
                if ($member instanceof TraitUse) {
                    foreach ($member->traits as $traitName) {
                        $uses[] = strtolower(ltrim($traitName->toString(), '\\'));
                    }
                }
            }
            $traits[$lc] = ['hasProp' => $hasProp, 'uses' => $uses];
        }
    }

    /**
     * @param array<string, array{hasProp: bool, uses: list<string>}> $traits
     * @param array<string, true> $visiting
     * @param array<string, bool> $resolved
     */
    private function traitTransitivelyHasProperties(
        string $lc,
        array $traits,
        array $visiting,
        array &$resolved
    ): bool {
        if (isset($resolved[$lc])) {
            return $resolved[$lc];
        }
        if (!isset($traits[$lc])) {
            return false;
        }
        if (isset($visiting[$lc])) {
            return false;
        }
        if ($traits[$lc]['hasProp']) {
            $resolved[$lc] = true;

            return true;
        }

        $visiting[$lc] = true;
        foreach ($traits[$lc]['uses'] as $usedLc) {
            if ($this->traitTransitivelyHasProperties($usedLc, $traits, $visiting, $resolved)) {
                $resolved[$lc] = true;

                return true;
            }
        }
        unset($visiting[$lc]);
        $resolved[$lc] = false;

        return false;
    }

    private function methodHasPromotedProperty(ClassMethod $method): bool
    {
        foreach ($method->params as $param) {
            if ($param instanceof Param && $this->isPromotedParam($param)) {
                return true;
            }
        }

        return false;
    }

    private function isPromotedParam(Param $param): bool
    {
        return 0 !== ($param->flags & (Class_::MODIFIER_PUBLIC | Class_::MODIFIER_PROTECTED | Class_::MODIFIER_PRIVATE));
    }

    private function fatal(string $enumName, Node $node): void
    {
        $file = $node->getAttribute('fileName', 'unknown');
        if (!is_string($file) || '' === $file) {
            $file = 'unknown';
        }

        throw new CompileFatal(
            $file,
            max(1, $node->getStartLine()),
            self::messageFor($enumName)
        );
    }
}
