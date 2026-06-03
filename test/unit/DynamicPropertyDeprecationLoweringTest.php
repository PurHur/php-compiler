<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Block::containsDynamicPropertyDeprecationOpcodes; VM fallback until MCJIT execute green (#5111).
 */
final class DynamicPropertyDeprecationLoweringTest extends TestCase
{
    public function testUndeclaredWriteRequiresVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$c = new C();
$c->y = 2;
PHP,
            'dyn.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsDynamicPropertyDeprecationOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testAllowDynamicPropertiesDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
#[\AllowDynamicProperties]
class D {}
$d = new D();
$d->z = 1;
PHP,
            'allow.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsDynamicPropertyDeprecationOpcodes($block));
    }
}
