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
            'must not be weaker than set visibility'
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
            'must not be weaker than set visibility'
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
            AsymmetricVisibilityRewriter::MULTIPLE_MODIFIERS_MESSAGE
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

    public function testMultipleAccessModifiersStillCompileErrors(): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    private(set) public string $x = 'a';
}
PHP, 'asymmetric_order.php');
            $this->fail('Expected compile failure');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                AsymmetricVisibilityCompileCheck::MULTIPLE_MODIFIERS_MESSAGE,
                $e->getMessage()
            );
        }
    }

    private function expectCompileError(string $code, string $messageNeedle): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile($code, 'asymmetric_visibility_compile.php');
            $this->fail('Expected CompileError');
        } catch (\CompileError $e) {
            $this->assertStringContainsString($messageNeedle, $e->getMessage());
        }
    }
}
