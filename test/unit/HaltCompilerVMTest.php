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
        $this->assertSame("missing\nbefore halt\n", $out);
    }

    public function testCompilerHaltOffsetMatchesTrailingBoundary(): void
    {
        $code = <<<'PHP'
<?php
echo __COMPILER_HALT_OFFSET__, "\n";
__halt_compiler();
TRAILING
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'halt_offset_probe.php');
        $this->assertNotNull($block);
        $this->assertSame(61, $runtime->compiler->getHaltCompilerOffset());
        $this->assertSame(61, $block->haltCompilerOffset);

        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame("61\n", $out);
    }

    public function testJitEmbedPreservesHaltOffset(): void
    {
        $code = <<<'PHP'
<?php
echo __COMPILER_HALT_OFFSET__, "\n";
__halt_compiler();
TRAILING
PHP;
        $embedded = \PHPCompiler\JitMcjitEmbed::prepareClassless($code);
        $this->assertNotSame($code, $embedded);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($embedded, 'halt_jit_embed.php');
        $this->assertNotNull($block);
        $runtime->compiler->reconcileHaltCompilerOffsetFromSource($code);
        $this->assertSame(61, $runtime->compiler->getHaltCompilerOffset());
        $block->haltCompilerOffset = $runtime->compiler->getHaltCompilerOffset();

        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame("61\n", $out);
    }
}
