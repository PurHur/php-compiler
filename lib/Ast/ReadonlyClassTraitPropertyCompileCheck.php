<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Param;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Compile-time check: readonly classes cannot use traits with non-readonly properties (#26592).
 *
 * php-src: Zend/zend_compile.c / Zend/zend_inheritance.c — readonly class + trait property
 * composition rejects any non-readonly instance or static property (including promoted ctor
 * params and nested trait imports). Message attributes the property to the trait named in
 * the class `use` clause (not the nested declaring trait).
 *
 * NameResolver has not resolved TraitUse / Trait_ namespacedName yet when beforeTraverse runs,
 * so this visitor tracks Namespace_ itself and qualifies relative trait names like Zend.
 */
final class ReadonlyClassTraitPropertyCompileCheck extends NodeVisitorAbstract
{
    /**
     * Trait FQCN (lowercase) => display FQCN + ordered non-readonly prop names + nested uses.
     *
     * @var array<string, array{display: string, nonReadonly: list<string>, uses: list<string>}>
     */
    private array $traits = [];

    public static function messageFor(string $classDisplay, string $traitDisplay, string $propName): string
    {
        return "Readonly class {$classDisplay} cannot use trait with a non-readonly property {$traitDisplay}::\${$propName}";
    }

    public function beforeTraverse(array $nodes)
    {
        $this->traits = [];
        $this->collectTraits($nodes, '');

        return null;
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Class_) {
            return null;
        }
        if (0 === ($node->flags & Class_::MODIFIER_READONLY)) {
            return null;
        }

        $classDisplay = $this->classDisplayName($node);
        $ns = $this->namespaceOf($classDisplay, $node);
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $traitName) {
                $lc = $this->resolveTraitLc($traitName, $ns);
                $prop = $this->firstNonReadonlyProperty($lc, []);
                if (null === $prop) {
                    continue;
                }
                $traitDisplay = $this->traits[$lc]['display'] ?? $this->displayTraitName($traitName, $ns);
                $this->fatal(
                    self::messageFor($classDisplay, $traitDisplay, $prop),
                    $stmt
                );
            }
        }

        return null;
    }

    /**
     * Own non-readonly properties first (declaration order), then nested uses — matches Zend (#26592).
     *
     * @param array<string, true> $visiting
     */
    private function firstNonReadonlyProperty(string $lc, array $visiting): ?string
    {
        if (!isset($this->traits[$lc]) || isset($visiting[$lc])) {
            return null;
        }
        $meta = $this->traits[$lc];
        if ([] !== $meta['nonReadonly']) {
            return $meta['nonReadonly'][0];
        }
        $visiting[$lc] = true;
        foreach ($meta['uses'] as $usedLc) {
            $found = $this->firstNonReadonlyProperty($usedLc, $visiting);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<Node> $nodes
     */
    private function collectTraits(array $nodes, string $ns): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $childNs = null !== $node->name ? $node->name->toString() : '';
                $this->collectTraits($node->stmts, $childNs);
                continue;
            }
            if (!$node instanceof Trait_) {
                continue;
            }

            $fqcn = null !== $node->namespacedName
                ? $node->namespacedName->toString()
                : $this->qualify($node->name->toString(), $ns);
            $lc = strtolower(ltrim($fqcn, '\\'));
            $nonReadonly = [];
            $uses = [];
            foreach ($node->stmts as $member) {
                if ($member instanceof Property) {
                    if (0 === ($member->flags & Class_::MODIFIER_READONLY)) {
                        foreach ($member->props as $prop) {
                            $nonReadonly[] = $prop->name->toString();
                        }
                    }
                    continue;
                }
                if ($member instanceof ClassMethod) {
                    foreach ($this->promotedNonReadonlyNames($member) as $name) {
                        $nonReadonly[] = $name;
                    }
                    continue;
                }
                if ($member instanceof TraitUse) {
                    foreach ($member->traits as $traitName) {
                        $uses[] = $this->resolveTraitLc($traitName, $ns);
                    }
                }
            }
            $this->traits[$lc] = [
                'display' => $fqcn,
                'nonReadonly' => $nonReadonly,
                'uses' => $uses,
            ];
        }
    }

    private function resolveTraitLc(Name $traitName, string $ns): string
    {
        return strtolower(ltrim($this->displayTraitName($traitName, $ns), '\\'));
    }

    private function displayTraitName(Name $traitName, string $ns): string
    {
        if ($traitName->isFullyQualified()) {
            return ltrim($traitName->toString(), '\\');
        }
        if ($traitName instanceof Name\Relative) {
            $rel = $traitName->toString();
            // Name\Relative::toString() includes leading "namespace\" in some versions; strip it.
            if (str_starts_with(strtolower($rel), 'namespace\\')) {
                $rel = substr($rel, strlen('namespace\\'));
            }

            return $this->qualify($rel, $ns);
        }

        return $this->qualify($traitName->toString(), $ns);
    }

    private function qualify(string $name, string $ns): string
    {
        $name = ltrim($name, '\\');
        if ('' === $ns) {
            return $name;
        }

        return $ns . '\\' . $name;
    }

    /**
     * Namespace of a class from its display FQCN (or namespacedName).
     */
    private function namespaceOf(string $classDisplay, Class_ $class): string
    {
        if (null !== $class->namespacedName) {
            $fqcn = $class->namespacedName->toString();
        } else {
            $fqcn = $classDisplay;
        }
        $pos = strrpos($fqcn, '\\');
        if (false === $pos) {
            return '';
        }

        return substr($fqcn, 0, $pos);
    }

    /**
     * @return list<string>
     */
    private function promotedNonReadonlyNames(ClassMethod $method): array
    {
        $names = [];
        foreach ($method->params as $param) {
            if (!$param instanceof Param || !$this->isPromotedParam($param)) {
                continue;
            }
            if (0 !== ($param->flags & Class_::MODIFIER_READONLY)) {
                continue;
            }
            if (null === $param->var || !property_exists($param->var, 'name')) {
                continue;
            }
            $names[] = (string) $param->var->name;
        }

        return $names;
    }

    private function isPromotedParam(Param $param): bool
    {
        return 0 !== ($param->flags & (Class_::MODIFIER_PUBLIC | Class_::MODIFIER_PROTECTED | Class_::MODIFIER_PRIVATE));
    }

    private function classDisplayName(Class_ $class): string
    {
        if (null !== $class->namespacedName) {
            return $class->namespacedName->toString();
        }
        if (null !== $class->name) {
            return $class->name->toString();
        }

        return 'class@anonymous';
    }

    private function fatal(string $message, Node $node): void
    {
        $file = $node->getAttribute('fileName', 'unknown');
        if (!is_string($file) || '' === $file) {
            $file = 'unknown';
        }

        throw new CompileFatal(
            $file,
            max(1, $node->getStartLine()),
            $message
        );
    }
}
