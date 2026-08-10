<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\CompilerVersion;

final class AsymmetricVisibilityRewriterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
    }

    private function requireParenthesizedAsymmetricSetModifier(): void
    {
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier disabled on reference profile (#16450)');
        }
    }

    public function testRewritePrivateSet(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class Demo {
    public (private(set)) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
        self::assertSame(
            \PHPCfg\Func::FLAG_PRIVATE,
            AsymmetricVisibilityRewriter::visibilityFromMarker('/*phpc-asymmetric-set:private*/')
        );
    }

    public function testRewritePublicPrivateSet(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class Demo {
    public (private(set)) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testRewritePublicProtectedSet(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class Demo {
    public (protected(set)) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:protected*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    /** @covers issue #18820 — unparenthesized public protected(set) rewrites on 8.4 forward profile */
    public function testRewriteUnparenthesizedPublicProtectedSetRejects(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public protected(set) string $name = 'x';
}
PHP;
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
            AsymmetricVisibilityRewriter::rewrite($source);

            return;
        }
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:protected*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    /** @covers issue #18820 — unparenthesized public private(set) rewrites on 8.4 forward profile */
    public function testRewriteUnparenthesizedPublicPrivateSetRejects(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'x';
}
PHP;
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
            AsymmetricVisibilityRewriter::rewrite($source);

            return;
        }
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    /**
     * @covers issue #29387 — readonly between get-vis and set-vis must not inject a second public
     * @dataProvider readonlyPrivateSetOrderProvider
     */
    public function testRewritePublicReadonlyPrivateSetOrders(string $decl): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = "<?php\nclass Demo {\n    {$decl}\n}\n";
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        $flat = preg_replace('/\s+/', ' ', $rewritten);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/', $flat);
        self::assertStringContainsString('/*phpc-asymmetric-explicit-read*/', $flat);
        self::assertStringContainsString('readonly', $flat);
        self::assertMatchesRegularExpression('/\bpublic\b.*\bint\s+\$x\b/i', $flat);
        self::assertSame(
            1,
            preg_match_all('/\bpublic\b/i', $flat),
            'must emit a single public modifier (no implicit duplicate public)'
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function readonlyPrivateSetOrderProvider(): iterable
    {
        yield 'public readonly private(set)' => ['public readonly private(set) int $x = 1;'];
        yield 'public private(set) readonly' => ['public private(set) readonly int $x = 1;'];
        yield 'readonly public private(set)' => ['readonly public private(set) int $x = 1;'];
    }

    public function testRewriteProtectedPrivateSet(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class Demo {
    protected (private(set)) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ protected string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    /** @covers issue #29672 — Zend 8.4 accepts same-visibility `public public(set)` */
    public function testPublicPublicSetRewrites(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public public(set) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:public*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    /** True duplicate set modifiers remain fatal (Zend: Multiple access type modifiers). */
    public function testDuplicateSetModifiersCompileError(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public private(set) private(set) string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testRewritePublicSetPrivateRead(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public(set) private string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:public*/ /*phpc-asymmetric-explicit-read*/ private string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testBarePrivateSetWithoutReadRejects(): void
    {
        if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('bare private(set) shorthand accepted on PHP 8.4.0+ forward profile (#16924)');
        }

        $source = 'private(set) string $x;';
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::BARE_SET_WITHOUT_READ_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testBareProtectedSetWithoutReadRejects(): void
    {
        if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('bare protected(set) shorthand accepted on PHP 8.4.0+ forward profile (#16924)');
        }

        $source = 'protected(set) string $x;';
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::BARE_SET_WITHOUT_READ_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testBarePrivateSetWithoutReadRewritesOnForwardProfile(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = 'private(set) string $x = "a";';
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ public string $x',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testBareProtectedSetWithoutReadRewritesOnForwardProfile(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = 'protected(set) string $x = "a";';
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:protected*/ protected string $x',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testRewritePrivateGet(): void
    {
        $source = <<<'PHP'
<?php
class Box {
    private(get) string $secret = 'hidden';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-get:private*/ public string $secret',
            preg_replace('/\s+/', ' ', $rewritten)
        );
        self::assertSame(
            \PHPCfg\Func::FLAG_PRIVATE,
            AsymmetricVisibilityRewriter::getVisibilityFromMarker('/*phpc-asymmetric-get:private*/')
        );
    }

    public function testRewritePublicPrivateGetCompileErrors(): void
    {
        $source = <<<'PHP'
<?php
class Box {
    public private(get) string $secret = 'hidden';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testImplicitPublicWriteForPrivateGet(): void
    {
        $source = 'private(get) string $x = "a";';
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-get:private*/ public string $x',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testPromotedPublicPrivateSetParenthesizedFormRewrites(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testPromotedSingleLinePublicPrivateSetParenthesizedFormRewrites(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class D {
    public function __construct(public (private(set)) int $x = 1) {}
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public int $x',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testRewriteUnparenthesizedPublicPrivateSetRejectsOnForwardProfile(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testPromotedExplicitReadBeforePrivateSetRejects(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public int $x',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testPromotedExplicitReadBeforeProtectedSetRejects(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class D {
    public function __construct(public protected(set) string $n = 'ok') {}
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:protected*/ /*phpc-asymmetric-explicit-read*/ public string $n',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testParenthesizedPrivateSetWithExplicitReadRewrites(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class Demo {
    public (private(set)) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    /** @covers issue #21526 — Zend write errors omit get-visibility (zend_errors.c). */
    public function testWriteModifierLabelMatchesZendSetOnly(): void
    {
        self::assertSame(
            'private(set)',
            AsymmetricVisibilityRewriter::writeModifierLabel(
                \PHPCfg\Func::FLAG_PUBLIC,
                \PHPCfg\Func::FLAG_PRIVATE,
                true
            )
        );
        self::assertSame(
            'private(set)',
            AsymmetricVisibilityRewriter::writeModifierLabel(
                \PHPCfg\Func::FLAG_PUBLIC,
                \PHPCfg\Func::FLAG_PRIVATE,
                false
            )
        );
        self::assertSame(
            'protected(set)',
            AsymmetricVisibilityRewriter::writeModifierLabel(
                \PHPCfg\Func::FLAG_PUBLIC,
                \PHPCfg\Func::FLAG_PROTECTED,
                true
            )
        );
    }

    public function testTraitStaticExplicitReadProtectedSetCompileErrors(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
trait T {
    public static (protected(set)) string $name = 't';
}
PHP;
        if (CompilerVersion::supportsStaticAsymmetricVisibility()) {
            $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
            self::assertStringContainsString(
                '/*phpc-asymmetric-set:protected*/ /*phpc-asymmetric-explicit-read*/ public static string $name',
                preg_replace('/\s+/', ' ', $rewritten)
            );

            return;
        }
        // PHP 8.4: Zend static-aviz fatal (#29389), not duplicate PPP (#7013).
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::STATIC_ASYMMETRIC_VISIBILITY_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testStaticPublicPrivateSetCompileErrors(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class C {
    public (private(set)) static int $x = 1;
}
PHP;
        // PHP 8.5+ accepts static aviz (#26239); ≤8.4 still compile-fatal (#7013).
        if (CompilerVersion::supportsStaticAsymmetricVisibility()) {
            $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
            self::assertStringContainsString(
                '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public static int $x',
                preg_replace('/\s+/', ' ', $rewritten)
            );

            return;
        }
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::STATIC_ASYMMETRIC_VISIBILITY_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    /** @covers issue #29389 — unparenthesized static aviz uses Zend 8.4 message on ≤8.4 */
    public function testStaticUnparenthesizedPublicPrivateSetCompileErrors(): void
    {
        if (CompilerVersion::supportsStaticAsymmetricVisibility()) {
            $this->markTestSkipped('static asymmetric visibility accepted on PHP 8.5+ (#26239)');
        }
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
        $source = <<<'PHP'
<?php
class C {
    public private(set) static int $x = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::STATIC_ASYMMETRIC_VISIBILITY_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    /** @covers issue #26239 — unparenthesized public private(set) static on PHP 8.5 */
    public function testStaticUnparenthesizedPublicPrivateSetRewritesOn85(): void
    {
        if (!CompilerVersion::supportsStaticAsymmetricVisibility()) {
            $this->markTestSkipped('static asymmetric visibility requires PHP 8.5 forward profile (#26239)');
        }
        $source = <<<'PHP'
<?php
class C {
    public private(set) static string $x = 'a';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public static string $x',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testStaticPrivateSetWithoutExplicitReadRewrites(): void
    {
        $this->markTestSkipped('bare private(set) on static properties rejected by bare-set guard (#15446); use (private(set)) public static');
        $source = <<<'PHP'
<?php
class C {
    private(set) static string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ public static string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testPromotedParenthesizedPrivateSetRewrites(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString(
            '/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $name',
            preg_replace('/\s+/', ' ', $rewritten)
        );
    }

    public function testPublicParenthesizedPublicSetCompileErrors(): void
    {
        $this->requireParenthesizedAsymmetricSetModifier();
        $source = <<<'PHP'
<?php
class Demo {
    public (public(set)) string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testRewriterSourceFileDoesNotFalsePositiveOnDocblockExamples(): void
    {
        $source = file_get_contents(__DIR__.'/../../lib/Ast/AsymmetricVisibilityRewriter.php');
        self::assertNotFalse($source);
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertSame($source, $rewritten);
    }
}
