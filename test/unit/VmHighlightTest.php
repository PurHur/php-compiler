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
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('<span', $html);
        $this->assertStringContainsString('echo', $html);
        $this->assertGreaterThan(20, \strlen($html));
    }

    public function testHighlightEngineReferenceProfileUsesCodeSpanNbsp(): void
    {
        // Unset PROFILE on 8.4.0-dev → Zend 8.2 wire (#25063); not bare languageProfile ≥ 8.3.
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(HighlightEngine::usesPreCodeWrapper());
            $html = HighlightEngine::render('<?php echo 1;');
            $this->assertMatchesRegularExpression('/<code><span/', $html);
            $this->assertStringContainsString('&nbsp;', $html);
            $this->assertStringNotContainsString('<pre>', $html);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHighlightEngineForwardProfileUsesPreCodeWrapper(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(HighlightEngine::usesPreCodeWrapper());
            $html = HighlightEngine::render('<?php echo 1;');
            $expected = '<pre><code style="color: #000000"><span style="color: #0000BB">&lt;?php </span><span style="color: #007700">echo </span><span style="color: #0000BB">1</span><span style="color: #007700">;</span></code></pre>';
            $this->assertSame($expected, $html);
            $this->assertStringNotContainsString('&nbsp;', $html);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHighlightEngineLegacyProfileUsesCodeSpanNbsp(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(HighlightEngine::usesPreCodeWrapper());
            $html = HighlightEngine::render('<?php echo 1; ?>');
            $this->assertMatchesRegularExpression('/<code><span/', $html);
            $this->assertStringContainsString('&nbsp;', $html);
            $this->assertStringNotContainsString('<pre>', $html);
            // Host Zend 8.2 inserts newlines around the outer span; we omit them (#24874).
            // Shape parity (wrapper / nbsp / no pre) is the gate — not byte identity.
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHighlightEngineLegacyUsesBrBetweenLines(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $code = "<?php\nline1\nline2\n";
            $html = HighlightEngine::render($code);
            $this->assertGreaterThanOrEqual(2, substr_count($html, '<br'));
            $this->assertStringNotContainsString("line1\n", $html);
            $this->assertStringContainsString('<code>', $html);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHighlightEngineModernPreservesRawNewlines(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = "line1\nline2\n";
            $html = HighlightEngine::render($code);
            $this->assertSame(0, substr_count($html, '<br'));
            $this->assertStringContainsString("line1\nline2", $html);
            $this->assertStringContainsString('<pre>', $html);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmHighlightReturnMode(): void
    {
        $html = VmHighlight::highlightString('<?php echo 1; ?>', true);
        $this->assertIsString($html);
        $this->assertStringContainsString('<code', $html);
    }
}
