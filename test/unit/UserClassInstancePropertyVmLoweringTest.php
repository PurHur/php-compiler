<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * User-class declared instance properties — MCJIT execute (#5111).
 */
final class UserClassInstancePropertyVmLoweringTest extends TestCase
{
    public function testDeclaredPropertyRequiresVmLoweringUntilMcjitVerifyGreen(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public int $x = 1;
}
echo "ok\n";
PHP,
            'declare_typed.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsUserClassDeclaredInstancePropertyOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testMethodOnlyClassDoesNotRequireVmLoweringForProperties(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public function f(): int { return 1; }
}
echo C::f(), "\n";
PHP,
            'method_only.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsUserClassDeclaredInstancePropertyOpcodes($block));
    }
}
