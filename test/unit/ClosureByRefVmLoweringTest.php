<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Closure use() capture detection; by-ref no longer forces requiresVmLowering (#4625).
 */
final class ClosureByRefVmLoweringTest extends TestCase
{
    public function testClosureByValueDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
$n = 1;
$f = function () use ($n) {
    echo $n;
};
PHP,
            'closure_use_value.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsClosureUseCaptureOpcodes($block));
        $this->assertFalse(Block::containsClosureByRefCaptureOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    public function testClosureByRefDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
$n = 0;
$f = function () use (&$n) {
    $n++;
};
PHP,
            'closure_use_byref.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsClosureByRefCaptureOpcodes($block));
        $this->assertTrue(Block::containsClosureUseCaptureOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }
}
