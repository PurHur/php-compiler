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

    public function testRewriteUnparenthesizedPublicProtectedSetRejects(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public protected(set) string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    /** @covers issue #18805 — unparenthesized explicit read + set rejected on 8.4 profile */
    public function testRewriteUnparenthesizedPublicPrivateSetRejects(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
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

    public function testPublicPublicSetCompileErrors(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public public(set) string $name = 'x';
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
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

    public function testWriteModifierLabelIncludesExplicitRead(): void
    {
        self::assertSame(
            'public private(set)',
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
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
