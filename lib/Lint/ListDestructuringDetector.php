<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\List_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * php-cfg lowers list/short-list destructuring assignments; surface #139 from the AST (see #297).
 */
final class ListDestructuringDetector
{
    public const KIND = 'Expr_List';

    /**
     * @return list<Issue>
     */
    public function detect(string $code, string $filename): array
    {
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        try {
            $ast = $parser->parse($code);
        } catch (\PhpParser\Error $e) {
            return [];
        }
        if (!is_array($ast)) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{int, string}> */
            public array $hits = [];

            public function enterNode(Node $node): ?int
            {
                if (!$node instanceof Assign) {
                    return null;
                }
                if ($node->var instanceof List_ || $node->var instanceof Array_) {
                    $this->hits[] = [$node->getStartLine(), ListDestructuringDetector::KIND];
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $issues = [];
        foreach ($visitor->hits as [$line, $kind]) {
            $tracking = UnsupportedRegistry::trackingIssueForKind($kind);
            $issues[] = new Issue(
                $filename,
                $line,
                $kind,
                "Unsupported expression: {$kind}",
                $tracking
            );
        }

        return $issues;
    }
}
