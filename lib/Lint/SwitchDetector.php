<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PhpParser\Node;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * switch compiles in the VM but JIT leaves TYPE_CASE stubbed (#96); lint flags AST nodes.
 */
final class SwitchDetector
{
    public const KIND = 'Stmt_Switch';

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

        $visitor = new SwitchDetectorAstVisitor();
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
                "Unsupported statement: {$kind}",
                $tracking
            );
        }

        return $issues;
    }
}


/** @internal */
final class SwitchDetectorAstVisitor extends NodeVisitorAbstract
{
    /** @var list<array{int, string}> */
    public array $hits = [];

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Switch_) {
            $this->hits[] = [$node->getStartLine(), SwitchDetector::KIND];
        }

        return null;
    }
}
