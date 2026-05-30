<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * bin/jit.php VM-fallback when script uses closure use ($var) / use (&$var) (#72, #2483).
 */
final class ClosureByRefVmLoweringTest extends TestCase
{
    public function testClosureByValueRequiresVmLowering(): void
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
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testClosureByRefRequiresVmLowering(): void
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
        $this->assertTrue(Block::requiresVmLowering($block));
    }
}
