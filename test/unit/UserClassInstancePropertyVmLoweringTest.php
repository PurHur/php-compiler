<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * MCJIT link/execute segfaults on user-class declared instance properties (#5111).
 */
final class UserClassInstancePropertyVmLoweringTest extends TestCase
{
    public function testDeclaredPropertyRequiresVmLowering(): void
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
