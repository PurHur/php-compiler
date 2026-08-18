<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Compile-time check: declare(strict_types=…) must be a file-level leading declare (#32182).
 *
 * php-cfg {@see \PHPCfg\Parser::parseStmt_Declare()} silently applies a late
 * {@code strict_types} pragma to the current function. php-src fatals first.
 *
 * php-src: Zend/zend_compile.c — zend_compile_declare() + zend_is_first_statement()
 */
final class StrictTypesDeclareCompileCheck extends NodeVisitorAbstract
{
    public const FIRST_STATEMENT_MESSAGE =
        'strict_types declaration must be the very first statement in the script';

    public const BLOCK_MODE_MESSAGE = 'strict_types declaration must not use block mode';

    public const VALUE_MESSAGE = 'strict_types declaration must have 0 or 1 as its value';

    private string $sourceFile = 'unknown';

    /** @var array<int, true> spl_object_id of file-level leading Declare_ nodes */
    private array $leadingDeclareIds = [];

    public function setSourceFile(string $sourceFile): void
    {
        $this->sourceFile = '' !== $sourceFile ? $sourceFile : 'unknown';
    }

    public function beforeTraverse(array $nodes)
    {
        $this->leadingDeclareIds = [];
        $seenNonTrivia = false;
        foreach ($nodes as $stmt) {
            if (!$stmt instanceof Node) {
                continue;
            }
            if ($stmt instanceof Nop) {
                continue;
            }
            if (!$seenNonTrivia && $stmt instanceof InlineHTML
                && preg_match('/\A#!.*\r?\n\z/', $stmt->value)
            ) {
                continue;
            }
            $seenNonTrivia = true;
            if ($stmt instanceof Declare_) {
                $this->leadingDeclareIds[spl_object_id($stmt)] = true;
                continue;
            }
            break;
        }

        return null;
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Declare_ || !$this->hasStrictTypes($node)) {
            return null;
        }

        // Match zend_compile_declare() order: first-statement, then block mode, then value.
        if (!isset($this->leadingDeclareIds[spl_object_id($node)])) {
            $this->fatal($node, self::FIRST_STATEMENT_MESSAGE);
        }
        if (null !== $node->stmts) {
            $this->fatal($node, self::BLOCK_MODE_MESSAGE);
        }
        $this->assertStrictTypesValue($node);

        return null;
    }

    private function hasStrictTypes(Declare_ $node): bool
    {
        foreach ($node->declares as $item) {
            if ('strict_types' === $item->key->toLowerString()) {
                return true;
            }
        }

        return false;
    }

    private function assertStrictTypesValue(Declare_ $node): void
    {
        foreach ($node->declares as $item) {
            if ('strict_types' !== $item->key->toLowerString()) {
                continue;
            }
            if ($item->value instanceof \PhpParser\Node\Scalar\LNumber
                && (0 === $item->value->value || 1 === $item->value->value)
            ) {
                continue;
            }
            $this->fatal($node, self::VALUE_MESSAGE);
        }
    }

    /** @return never */
    private function fatal(Node $node, string $message): void
    {
        $file = $node->getAttribute('fileName');
        if (!is_string($file) || '' === $file) {
            $file = $this->sourceFile;
        }

        throw new CompileFatal($file, max(1, $node->getStartLine()), $message);
    }
}
