<?php

declare(strict_types=1);

namespace PHPCompiler;

/** Array-literal element snapshot across nested ?: (#36380). */
final class InlineTernaryArrayDim36380Test extends \PHPUnit\Framework\TestCase
{
    public function testBlockListShapeReproDoesNotOomUnderVm(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/inline_ternary_array_dim_36380.php'
        ));
        $this->assertSame("OK\n", ob_get_clean());
    }
}
