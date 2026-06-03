<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * MCJIT IR compile for dynamic property writes (#5111).
 */
final class DynamicPropertyDeprecationJitCompileTest extends TestCase
{
    public function testUndeclaredWriteLowersToIrWithoutVmLoweringGate(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$c = new C();
$c->y = 2;
PHP;
        $block = $runtime->parseAndCompile($code, 'dyn.php');
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsDynamicPropertyDeprecationOpcodes($block));
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
