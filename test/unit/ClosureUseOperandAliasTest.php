<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * JIT assign must resolve php-cfg duplicate temporaries for one scope slot (#72).
 */
final class ClosureUseOperandAliasTest extends TestCase
{
    public function testClosureUseReassignAfterCaptureCompilesViaVmFallback(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
$n = 10;
$f = function ($x) use ($n) {
    return $x + $n;
};
$n = 99;
echo $f(5), "\n";
PHP,
            'closure_use_reassign.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsClosureUseCaptureOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }
}
