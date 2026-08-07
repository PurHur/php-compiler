<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
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
        self::assertMatchesRegularExpression(
            '/\/\*\s*phpc-lazy-property\s*\*\/\s+public\s+string/',
            $rewritten
        );
    }

    public function testLazyModifierSetsClassPropertyFlagAndReflectionNames(): void
    {
        $code = <<<'PHP'
<?php
class LazyDecl {
    public lazy string $a = '1';
    public string $b = '2';
}
echo method_exists(ReflectionClass::class, 'getLazyPropertyNames') ? "phantom\n" : "absent\n";
$c = new LazyDecl();
$rp = new ReflectionProperty(LazyDecl::class, 'a');
echo $rp->isLazy($c) ? "lazy\n" : "not-lazy\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("absent\nlazy\n", ob_get_clean());
    }
}
