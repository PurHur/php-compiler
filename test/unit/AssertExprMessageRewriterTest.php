<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PHPCompiler\Ast\AssertExprMessageRewriter;
use PHPUnit\Framework\TestCase;

/**
 * AssertExprMessageRewriter injects Zend-style assert() expression text (#29630).
 */
final class AssertExprMessageRewriterTest extends TestCase
{
    public function testInjectsPrettyPrintedAssertCallAsDescription(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php assert($x === 1);');
        $this->assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new AssertExprMessageRewriter());
        $ast = $traverser->traverse($ast);

        $call = $ast[0]->expr;
        $this->assertCount(2, $call->args);
        $this->assertSame('assert($x === 1)', $call->args[1]->value->value);
    }

    public function testLeavesExplicitDescriptionAlone(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php assert(false, "custom");');
        $this->assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new AssertExprMessageRewriter());
        $ast = $traverser->traverse($ast);

        $call = $ast[0]->expr;
        $this->assertCount(2, $call->args);
        $this->assertSame('custom', $call->args[1]->value->value);
    }

    public function testNormalizesFullyQualifiedAssertNameInMessage(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php \\assert(false);');
        $this->assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new AssertExprMessageRewriter());
        $ast = $traverser->traverse($ast);

        $call = $ast[0]->expr;
        $this->assertCount(2, $call->args);
        $this->assertSame('assert(false)', $call->args[1]->value->value);
        $this->assertSame(
            'assert(false)',
            (new PrettyPrinter())->prettyPrintExpr(
                (static function ($call) {
                    $clone = clone $call;
                    $clone->args = [$call->args[0]];
                    $clone->name = new \PhpParser\Node\Name('assert');

                    return $clone;
                })($call)
            )
        );
    }
}
