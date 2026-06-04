<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * bin/jit.php driver for enum . string concat Error parity (#5806, zend_operators.c).
 */
final class EnumConcatJitDriverTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testJitDriverReproMatchesZendError(): void
    {
        $repro = $this->repoRoot.'/test/repro/maintainer_enum_string_concat.php';
        $this->assertFileExists($repro);
        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->repoRoot.'/bin/jit.php'),
            escapeshellarg($repro)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $text = implode("\n", $out);
        $this->assertStringContainsString('Object of class E could not be converted to string', $text);
        $this->assertStringContainsString('A|1', $text);
    }
}
