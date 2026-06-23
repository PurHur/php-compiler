<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\HighlightEngine;
use PHPCompiler\ext\standard\VmHighlight;
use PHPUnit\Framework\TestCase;

/** Issue #4824: VM highlight must not delegate to host \\highlight_string(). */
final class VmHighlightTest extends TestCase
{
    public function testVmHighlightDoesNotReferenceHostHighlight(): void
    {
        $source = file_get_contents(__DIR__.'/../../ext/standard/VmHighlight.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('\\highlight_string(', $source);
        $this->assertStringNotContainsString('\\highlight_file(', $source);
    }

    public function testHighlightEngineProducesCodeSpan(): void
    {
        $html = HighlightEngine::render('<?php echo 1; ?>');
        $this->assertStringContainsString('<code>', $html);
        $this->assertStringContainsString('<span', $html);
        $this->assertStringContainsString('echo', $html);
        $this->assertGreaterThan(20, \strlen($html));
    }

    public function testHighlightEngineMatchesZendByteLength(): void
    {
        $html = HighlightEngine::render('<?php echo 1; ?>');
        $zend = \highlight_string('<?php echo 1; ?>', true);
        $this->assertIsString($zend);
        $this->assertSame($zend, $html);
    }

    public function testVmHighlightReturnMode(): void
    {
        $html = VmHighlight::highlightString('<?php echo 1; ?>', true);
        $this->assertIsString($html);
        $this->assertStringContainsString('<code>', $html);
    }
}
