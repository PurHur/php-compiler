<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Resolve `use EnumName\CaseName` into enum case singleton fetches (#6219).
 *
 * php-parser NameResolver registers class aliases; enum case imports must lower
 * unqualified ConstFetch references to ClassConstFetch (zend_compile.c parity).
 */
final class EnumCaseImportRewriter extends NodeVisitorAbstract
{
    /** @var array<string, array<string, true>> lowercased enum FQCN -> canonical case name -> true */
    private array $enumCases = [];

    /** @var array<string, array{0: string, 1: string}> lowercased alias -> [enumFqcn, caseName] */
    private array $imports = [];

    private ?Name $namespace = null;

    public function beforeTraverse(array $nodes)
    {
        $this->collectEnums($nodes, null);

        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name;
        } elseif ($node instanceof Use_ && Use_::TYPE_NORMAL === $node->type) {
            foreach ($node->uses as $use) {
                $this->registerEnumCaseImport($use);
            }
        } elseif ($node instanceof Expr\ConstFetch && $node->name instanceof Name && $node->name->isUnqualified()) {
            $aliasKey = strtolower($node->name->toString());
            if (isset($this->imports[$aliasKey])) {
                [$enumFqcn, $caseName] = $this->imports[$aliasKey];

                return new Expr\ClassConstFetch(
                    new FullyQualified($enumFqcn, $node->name->getAttributes()),
                    new Identifier($caseName, $node->name->getAttributes()),
                    $node->getAttributes()
                );
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->namespace = null;
        }

        return null;
    }

    /**
     * @param array<int, Node> $nodes
     */
    private function collectEnums(array $nodes, ?Name $namespace): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $this->collectEnums($node->stmts ?? [], $node->name);
                continue;
            }
            if (!$node instanceof Enum_ || null === $node->name) {
                continue;
            }
            $shortName = $node->name->toString();
            $fqcn = null !== $namespace
                ? (string) Name::concat($namespace, $shortName)
                : $shortName;
            $cases = [];
            foreach ($node->stmts ?? [] as $stmt) {
                if ($stmt instanceof EnumCase) {
                    $cases[$stmt->name->toString()] = true;
                }
            }
            $this->enumCases[strtolower($fqcn)] = $cases;
        }
    }

    private function registerEnumCaseImport(UseUse $use): void
    {
        $importName = $use->name;
        if (!$importName->isQualified() && !$importName->isFullyQualified()) {
            return;
        }

        $fqn = $this->resolveImportName($importName);
        $parts = explode('\\', $fqn);
        if (\count($parts) < 2) {
            return;
        }

        $caseName = (string) array_pop($parts);
        $enumFqcn = implode('\\', $parts);
        $enumKey = strtolower($enumFqcn);
        if (!isset($this->enumCases[$enumKey])) {
            return;
        }
        if (!isset($this->enumCases[$enumKey][$caseName])) {
            $this->fatal(
                $use,
                sprintf('Case %s not found in enum %s', $caseName, $enumFqcn)
            );
        }

        $aliasKey = strtolower($use->getAlias()->toString());
        $this->imports[$aliasKey] = [$enumFqcn, $caseName];
    }

    private function resolveImportName(Name $name): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }
        if (null !== $this->namespace) {
            return (string) Name::concat($this->namespace, $name);
        }

        return $name->toString();
    }

    private function fatal(Node $node, string $message): void
    {
        $file = $node->getAttribute('fileName', 'unknown');
        if (!is_string($file) || '' === $file) {
            $file = 'unknown';
        }

        throw new CompileFatal($file, max(1, $node->getStartLine()), $message);
    }
}
