<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PHP 8.4 asymmetric visibility compile-time validation (#6589). */
final class AsymmetricVisibilityCompileCheckTest extends TestCase
{
    protected function setUp(): void
    {
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
    }
    public function testProtectedPublicSetCompileErrors(): void
    {
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier disabled on 8.4.0-dev reference profile (#16450)');
        }
        $this->expectCompileError(
            <<<'PHP'
<?php
class C {
    protected (public(set)) string $x = 'a';
}
PHP,
            AsymmetricVisibilityCompileCheck::WEAKER_THAN_SET_MESSAGE
        );
    }

    public function testPrivateProtectedSetCompileErrors(): void
    {
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier disabled on 8.4.0-dev reference profile (#16450)');
        }
        $this->expectCompileError(
            <<<'PHP'
<?php
class C {
    private (protected(set)) string $x = 'a';
}
PHP,
            AsymmetricVisibilityCompileCheck::WEAKER_THAN_SET_MESSAGE
        );
    }

    public function testUntypedAsymmetricSetCompileErrors(): void
    {
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier disabled on 8.4.0-dev reference profile (#16450)');
        }
        $this->expectCompileError(
            <<<'PHP'
<?php
class C {
    public (private(set)) $x = 1;
}
PHP,
            'must have type'
        );
    }

    public function testPublicPrivateSetCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'a';
}
PHP, AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
    }

    public function testPromotedPublicPrivateSetParenthesizedFormRejects(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}
PHP, 'syntax error, unexpected token "private"');
    }

    public function testPromotedSingleLinePublicPrivateSetParenthesizedFormRejects(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class D {
    public function __construct(public (private(set)) int $x = 1) {}
}
PHP, 'syntax error, unexpected token "private"');
    }

    public function testPromotedExplicitReadBeforePrivateSetCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
PHP, AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
    }

    public function testExplicitReadBeforePrivateSetCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'a';
}
PHP, AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE);
    }

    public function testValidParenthesizedPrivateSetStillCompiles(): void
    {
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier disabled on 8.4.0-dev reference profile (#16450)');
        }
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Demo {
    public (private(set)) string $name = 'a';
}
PHP, 'asymmetric_ok.php');
        $this->assertNotNull($block);
    }

    public function testStaticPublicPrivateSetCompileErrors(): void
    {
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier disabled on 8.4.0-dev reference profile (#16450)');
        }
        // PHP 8.5+ accepts static aviz (#26239); ≤8.4 still compile-fatal (#7013).
        if (CompilerVersion::supportsStaticAsymmetricVisibility()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public (private(set)) static int $x = 1;
}
PHP, 'static_asymmetric_ok.php');
            $this->assertNotNull($block);

            return;
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public (private(set)) static int $x = 1;
}
PHP, 'static_asymmetric_reject.php');
            $this->fail('Expected compile failure');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                AsymmetricVisibilityRewriter::STATIC_ASYMMETRIC_VISIBILITY_MESSAGE,
                $e->getMessage()
            );
        }
    }

    public function testSetBeforeReadRejects(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    private(set) public string $x = 'a';
}
PHP, AsymmetricVisibilityRewriter::BARE_SET_WITHOUT_READ_MESSAGE);
    }

    public function testBarePrivateSetRejects(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    private(set) string $p = 'x';
}
PHP, AsymmetricVisibilityRewriter::BARE_SET_WITHOUT_READ_MESSAGE);
    }

    private function expectCompileError(string $code, string $messageNeedle): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile($code, 'asymmetric_visibility_compile.php');
            $this->fail('Expected CompileError');
        } catch (\CompileError $e) {
            if (str_contains($messageNeedle, '%s')) {
                $this->assertStringContainsString('must not be weaker than set visibility', $e->getMessage());
            } else {
                $this->assertStringContainsString($messageNeedle, $e->getMessage());
            }
        }
    }
}
