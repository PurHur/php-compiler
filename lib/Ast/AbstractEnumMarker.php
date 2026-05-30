<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\NodeVisitorAbstract;

/**
 * Marks php-parser Enum_ nodes rewritten from `abstract enum` (#3737).
 */
final class AbstractEnumMarker extends NodeVisitorAbstract
{
    public const ATTR = 'phpCompilerAbstractEnum';

    /** @var array<int, true> */
    private array $abstractLines = [];

    /**
     * @param array<int, true> $abstractLines
     */
    public function setAbstractLines(array $abstractLines): void
    {
        $this->abstractLines = $abstractLines;
    }

    public function clear(): void
    {
        $this->abstractLines = [];
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Enum_) {
            return null;
        }
        if ([] === $this->abstractLines) {
            return null;
        }
        $line = $node->getStartLine();
        if (!isset($this->abstractLines[$line])) {
            return null;
        }
        $node->setAttribute(self::ATTR, true);

        return null;
    }
}
