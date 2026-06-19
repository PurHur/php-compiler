<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PHP 8.4 asymmetric visibility compile-time validation (#6589). */
final class AsymmetricVisibilityCompileCheckTest extends TestCase
{
    public function testProtectedPublicSetCompileErrors(): void
    {
        $this->expectCompileError(
            <<<'PHP'
<?php
class C {
    protected public(set) string $x = 'a';
}
PHP,
            AsymmetricVisibilityCompileCheck::MULTIPLE_MODIFIERS_MESSAGE
        );
    }

    public function testPrivateProtectedSetCompileErrors(): void
    {
        $this->expectCompileError(
            <<<'PHP'
<?php
class C {
    private protected(set) string $x = 'a';
}
PHP,
            AsymmetricVisibilityCompileCheck::MULTIPLE_MODIFIERS_MESSAGE
        );
    }

    public function testUntypedAsymmetricSetCompileErrors(): void
    {
        $this->expectCompileError(
            <<<'PHP'
<?php
class C {
    private(set) $x = 1;
}
PHP,
            'must have type'
        );
    }

    public function testPublicPrivateSetCompileErrors(): void
    {
        $this->expectCompileError(
            <<<'PHP'
<?php
class Demo {
    public private(set) string $name = 'a';
}
PHP,
            AsymmetricVisibilityCompileCheck::MULTIPLE_MODIFIERS_MESSAGE
        );
    }

    public function testPromotedPublicPrivateSetCompileErrors(): void
    {
        $this->expectCompileError(
            <<<'PHP'
<?php
class User {
    public function __construct(
        public private(set) string $name,
    ) {}
}
PHP,
            AsymmetricVisibilityCompileCheck::MULTIPLE_MODIFIERS_MESSAGE
        );
    }

    public function testPromotedSingleLinePublicPrivateSetCompileErrors(): void
    {
        $this->expectCompileError(
            <<<'PHP'
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
PHP,
            AsymmetricVisibilityCompileCheck::MULTIPLE_MODIFIERS_MESSAGE
        );
    }

    public function testValidPrivateSetStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Demo {
    private(set) string $name = 'a';
}
PHP, 'asymmetric_ok.php');
        $this->assertNotNull($block);
    }

    public function testStaticPublicPrivateSetCompileErrors(): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public private(set) static int $x = 1;
}
PHP, 'static_asymmetric_reject.php');
            $this->fail('Expected compile failure');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                AsymmetricVisibilityCompileCheck::MULTIPLE_MODIFIERS_MESSAGE,
                $e->getMessage()
            );
        }
    }

    public function testSetBeforeReadStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    private(set) public string $x = 'a';
}
PHP, 'asymmetric_order.php');
        $this->assertNotNull($block);
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
