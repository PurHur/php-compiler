<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * VM-only checks for __halt_compiler() (#3479).
 */
final class HaltCompilerVMTest extends TestCase
{
    public function testFunctionExistsAndRemainingBytes(): void
    {
        $code = <<<'PHP'
<?php
echo function_exists('__halt_compiler') ? "exists\n" : "missing\n";
echo "before halt\n";
__halt_compiler();
?>
TRAILING
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'halt_probe.php');
        $this->assertNotNull($block);
        $remaining = $runtime->compiler->getHaltCompilerRemaining();
        $this->assertIsString($remaining);
        $this->assertStringContainsString('TRAILING', $remaining);

        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame("exists\nbefore halt\n", $out);
    }
}
