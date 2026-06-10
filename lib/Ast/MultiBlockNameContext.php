<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\NameContext;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Preserve use-import alias tables when the same namespace is re-entered (#4425).
 *
 * PHP 8.3+ allows multiple namespace blocks per file; semicolon-style re-entry to
 * the same namespace must restore prior use function/const/class aliases (Zend
 * zend_compile.c namespace table merge).
 */
final class MultiBlockNameContext extends NameContext
{
    /** @var array<string, array{aliases: array<int, array<string, Name>>, origAliases: array<int, array<string, Name>>}> */
    private array $savedAliasesByNamespace = [];

    public function beginCompilationUnit(): void
    {
        $this->savedAliasesByNamespace = [];
        parent::startNamespace(null);
    }

    public function startNamespace(?Name $namespace = null): void
    {
        if (null !== $this->namespace || $this->hasAnyAlias()) {
            $this->savedAliasesByNamespace[$this->namespaceKey($this->namespace)] = $this->snapshotAliases();
        }

        $this->namespace = $namespace;

        $key = $this->namespaceKey($namespace);
        if (isset($this->savedAliasesByNamespace[$key])) {
            $snapshot = $this->savedAliasesByNamespace[$key];
            $this->aliases = $snapshot['aliases'];
            $this->origAliases = $snapshot['origAliases'];

            return;
        }

        $this->origAliases = $this->aliases = [
            Stmt\Use_::TYPE_NORMAL => [],
            Stmt\Use_::TYPE_FUNCTION => [],
            Stmt\Use_::TYPE_CONSTANT => [],
        ];
    }

    private function namespaceKey(?Name $namespace): string
    {
        return null === $namespace ? '' : $namespace->toString();
    }

    private function hasAnyAlias(): bool
    {
        foreach ($this->aliases as $table) {
            if ($table !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{aliases: array<int, array<string, Name>>, origAliases: array<int, array<string, Name>>}
     */
    private function snapshotAliases(): array
    {
        $copy = static function (array $table): array {
            $out = [];
            foreach ($table as $type => $aliases) {
                $out[$type] = $aliases;
            }

            return $out;
        };

        return [
            'aliases' => $copy($this->aliases),
            'origAliases' => $copy($this->origAliases),
        ];
    }
}
