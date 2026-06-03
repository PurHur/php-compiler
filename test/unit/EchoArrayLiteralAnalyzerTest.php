<?php
declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @covers issue #4964 */
final class EchoArrayLiteralAnalyzerTest extends TestCase
{
    public function testArrayLiteralEchoDoesNotTripDynamicAppendAnalyzer(): void
    {
        $runtime = new \PHPCompiler\Runtime();
        $code = <<<'PHP'
<?php
echo [1, 2];
PHP;
        $block = $runtime->parseAndCompile($code, 'echo_array_literal.php');
        self::assertNotNull($block);
        $jit = new JIT($runtime->loadJitContext());
        $jit->compile($block);
        self::assertTrue(true);
    }
}
