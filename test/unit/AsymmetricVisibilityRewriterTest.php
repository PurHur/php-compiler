<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;

final class AsymmetricVisibilityRewriterTest extends TestCase
{
    public function testRewritePrivateSet(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    private(set) string $name = 'x';
}
PHP;
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $name', preg_replace('/\s+/', ' ', $rewritten));
        self::assertSame(
            \PHPCfg\Func::FLAG_PRIVATE,
            AsymmetricVisibilityRewriter::visibilityFromMarker('/*phpc-asymmetric-set:private*/')
        );
    }

    public function testPublicPrivateSetCompileErrors(): void
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

    public function testPublicProtectedSetCompileErrors(): void
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

    public function testProtectedPrivateSetCompileErrors(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    protected private(set) string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
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
        self::assertStringContainsString('/*phpc-asymmetric-set:public*/ private string $name', preg_replace('/\s+/', ' ', $rewritten));
    }

    public function testImplicitPublicRead(): void
    {
        $source = 'private(set) string $x;';
        $rewritten = AsymmetricVisibilityRewriter::rewrite($source);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $x', preg_replace('/\s+/', ' ', $rewritten));
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

    public function testPromotedPublicPrivateSetCompileErrors(): void
    {
        $source = <<<'PHP'
<?php
class User {
    public function __construct(
        public private(set) string $name,
    ) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testPromotedSingleLinePublicPrivateSetCompileErrors(): void
    {
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

    public function testParenthesizedPrivateSetWithExplicitReadCompileErrors(): void
    {
        $source = <<<'PHP'
<?php
class Demo {
    public (private(set)) string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testTraitStaticExplicitReadProtectedSetCompileErrors(): void
    {
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
        $source = <<<'PHP'
<?php
class C {
    public private(set) static int $x = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testStaticPrivateSetWithoutExplicitReadStillRewrites(): void
    {
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

    public function testPromotedParenthesizedPrivateSetCompileErrors(): void
    {
        $source = <<<'PHP'
<?php
class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
        AsymmetricVisibilityRewriter::rewrite($source);
    }

    public function testPublicParenthesizedPublicSetCompileErrors(): void
    {
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
