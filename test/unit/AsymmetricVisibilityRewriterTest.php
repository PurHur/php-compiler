<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;

final class AsymmetricVisibilityRewriterTest extends TestCase
{
    public function testRewritePublicPrivateSet(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $name', preg_replace('/\s+/', ' ', $rewritten));
        self::assertSame(
            \PHPCfg\Func::FLAG_PRIVATE,
            AsymmetricVisibilityRewriter::visibilityFromMarker('/*phpc-asymmetric-set:private*/')
        );
    }

    public function testImplicitPublicRead(): void
    {
        $source = 'private(set) string $x;';
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $x', preg_replace('/\s+/', ' ', $rewritten));
    }
}
