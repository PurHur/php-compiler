<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\JIT;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * InstanceOf_ must not abort Analyzer::canEscape (#36382).
 *
 * Conservatively mark instanceof as escaping so packed-array storage is not used
 * (packing + instanceof previously segfaulted LLVM emitToFile).
 */
final class InstanceOfPackedArrayAnalyzer36382Test extends TestCase
{
    public function testInstanceOfUsageDoesNotTripEscapeAnalyzer(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = [1];
$b = $a instanceof \Traversable;
PHP;
        $block = $runtime->parseAndCompile($code, 'instanceof_packed_array.php');
        self::assertNotNull($block);
        $jit = new JIT($runtime->loadJitContext());
        $jit->compile($block);
        self::assertTrue(true);
    }
}
