<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

/** PHP 8.4 lazy property modifier source rewrite (#16813). */
final class LazyPropertyRewriterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!CompilerVersion::supportsLazyPropertyModifier()) {
            $this->markTestSkipped('lazy property modifier requires PHP_COMPILER_PROFILE=8.4');
        }
    }

    public function testRewritesLazyModifierAndRecoversFromAttributes(): void
    {
        $source = <<<'PHP'
<?php
class C {
    public lazy string $x = 'a';
}
PHP;
        $rewritten = LazyPropertyRewriter::rewrite($source);
        self::assertStringNotContainsString(' lazy ', $rewritten);
        self::assertStringContainsString('phpc-lazy-property', $rewritten);
        self::assertStringContainsString(
            '/*phpc-lazy-property*/ public string $x',
            preg_replace('/\s+/', ' ', $rewritten)
        );

        $parser = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::ONLY_PHP7);
        $ast = $parser->parse($rewritten);
        self::assertNotNull($ast);
        $prop = $ast[0]->stmts[0];
        self::assertTrue(LazyPropertyRewriter::isLazyFromAttributes($prop->getAttributes()));
    }
}
