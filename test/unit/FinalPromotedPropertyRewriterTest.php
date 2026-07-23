<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Ast\FinalPromotedPropertyRewriter;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

final class FinalPromotedPropertyRewriterTest extends TestCase
{
    public function testRewritesPublicFinalPromotedParam(): void
    {
        if (!CompilerVersion::supportsFinalProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.4');
        }
        $src = <<<'PHP'
<?php
class FP {
  public function __construct(public final string $x) {}
}
PHP;
        $out = FinalPromotedPropertyRewriter::rewrite($src);
        self::assertStringContainsString('phpc-promoted-final', $out);
        self::assertMatchesRegularExpression(
            '/\/\*\s*phpc-promoted-final\s*\*\/\s+public\s+string\s+\$x/',
            $out
        );
        self::assertStringNotContainsString('public final string', $out);
    }

    public function testRewritesPrivateAndProtectedFinal(): void
    {
        if (!CompilerVersion::supportsFinalProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.4');
        }
        $src = <<<'PHP'
<?php
class T {
  public function __construct(
    private final int $a,
    protected final string $b,
  ) {}
}
PHP;
        $out = FinalPromotedPropertyRewriter::rewrite($src);
        self::assertSame(2, preg_match_all('/phpc-promoted-final/', $out));
        self::assertStringNotContainsString('final int', $out);
        self::assertStringNotContainsString('final string', $out);
    }

    public function testBareFinalPromotesAsPublic(): void
    {
        if (!CompilerVersion::supportsFinalProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.4');
        }
        $src = '<?php class C { public function __construct(final string $x) {} }';
        $out = FinalPromotedPropertyRewriter::rewrite($src);
        self::assertMatchesRegularExpression(
            '/\/\*\s*phpc-promoted-final\s*\*\/\s+public\s+string\s+\$x/',
            $out
        );
    }

    public function testLeavesPlainFinalPropertyUntouched(): void
    {
        if (!CompilerVersion::supportsFinalProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.4');
        }
        $src = <<<'PHP'
<?php
class C {
  public final string $x = "a";
}
PHP;
        self::assertSame($src, FinalPromotedPropertyRewriter::rewrite($src));
    }

    public function testNoopOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsFinalProperties()) {
            self::markTestSkipped('reference-profile only');
        }
        $src = '<?php class C { public function __construct(public final string $x) {} }';
        self::assertSame($src, FinalPromotedPropertyRewriter::rewrite($src));
    }
}
