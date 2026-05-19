<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PhpParser\Node;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * php-cfg lowers ++/-- to assign+binary ops; lint still flags AST nodes until #137.
 */
final class IncrementDetector
{
    /** @var array<class-string<Node>, string> */
    public const NODE_TO_KIND = [
        PreInc::class => 'Expr_PreInc',
        PostInc::class => 'Expr_PostInc',
        PreDec::class => 'Expr_PreDec',
        PostDec::class => 'Expr_PostDec',
    ];

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

            public function enterNode(Node $node)
            {
                if (isset(IncrementDetector::NODE_TO_KIND[get_class($node)])) {
                    $this->hits[] = [$node->getStartLine(), IncrementDetector::NODE_TO_KIND[get_class($node)]];
                }
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
