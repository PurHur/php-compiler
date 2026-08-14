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
        if (!CompilerVersion::supportsFinalPromotedProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.5');
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
        if (!CompilerVersion::supportsFinalPromotedProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.5');
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
        if (!CompilerVersion::supportsFinalPromotedProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.5');
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
        if (!CompilerVersion::supportsFinalPromotedProperties()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.5');
        }
        $src = <<<'PHP'
<?php
class C {
  public final string $x = "a";
}
PHP;
        self::assertSame($src, FinalPromotedPropertyRewriter::rewrite($src));
    }

    public function testNoopOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertFalse(CompilerVersion::supportsFinalPromotedProperties());
            $src = '<?php class C { public function __construct(public final string $x) {} }';
            self::assertSame($src, FinalPromotedPropertyRewriter::rewrite($src));
            $err = FinalPromotedPropertyRewriter::referenceProfileSyntaxError($src);
            self::assertNotNull($err);
            self::assertSame(
                FinalPromotedPropertyRewriter::REFERENCE_PROFILE_FINAL_ON_PARAMETER,
                $err['message']
            );
            self::assertSame(
                FinalPromotedPropertyRewriter::REFERENCE_PROFILE_FINAL_ON_PARAMETER,
                FinalPromotedPropertyRewriter::referenceProfileRejectMessage()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testNoopOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsFinalPromotedProperties()) {
            self::markTestSkipped('reference-profile only');
        }
        $src = '<?php class C { public function __construct(public final string $x) {} }';
        self::assertSame($src, FinalPromotedPropertyRewriter::rewrite($src));
    }

    /** #31153 — PROFILE≤8.3 / unset: Zend parse error, not the 8.4 compile fatal. */
    public function testParseErrorOnProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            self::assertFalse(CompilerVersion::supportsFinalProperties());
            self::assertFalse(CompilerVersion::supportsFinalPromotedProperties());
            $src = '<?php class C { public function __construct(final public int $x) {} }';
            $err = FinalPromotedPropertyRewriter::referenceProfileSyntaxError($src);
            self::assertNotNull($err);
            self::assertSame(
                FinalPromotedPropertyRewriter::REFERENCE_PROFILE_PARSE_UNEXPECTED_FINAL,
                $err['message']
            );
            self::assertSame(
                FinalPromotedPropertyRewriter::REFERENCE_PROFILE_PARSE_UNEXPECTED_FINAL,
                FinalPromotedPropertyRewriter::referenceProfileRejectMessage()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** #31153 — PROFILE=8.3 matches 8.2 grammar (no T_FINAL in parameter list). */
    public function testParseErrorOnProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            self::assertFalse(CompilerVersion::supportsFinalProperties());
            $src = '<?php class C { public function __construct(final public int $x) {} }';
            $err = FinalPromotedPropertyRewriter::referenceProfileSyntaxError($src);
            self::assertNotNull($err);
            self::assertSame(
                FinalPromotedPropertyRewriter::REFERENCE_PROFILE_PARSE_UNEXPECTED_FINAL,
                $err['message']
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** #28481 — eval()/string payloads must not look like real promoted-final decls. */
    public function testIgnoresFinalPromotedSyntaxInsideStrings(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertFalse(CompilerVersion::supportsFinalPromotedProperties());
            $src = <<<'PHP'
<?php
$s = 'class C { public function __construct(public final int $x) {} }';
eval('class D { public function __construct(public final int $y) {} }');
echo "ok\n";
PHP;
            self::assertNull(FinalPromotedPropertyRewriter::referenceProfileSyntaxError($src));
            self::assertFalse(FinalPromotedPropertyRewriter::containsFinalPromotedPropertySyntax($src));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
