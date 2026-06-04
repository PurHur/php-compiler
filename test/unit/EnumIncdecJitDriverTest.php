<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * bin/jit.php driver for enum ++/-- TypeError parity (#5606, #5525).
 */
final class EnumIncdecJitDriverTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testJitDriverReproMatchesZendMessages(): void
    {
        $repro = $this->repoRoot.'/test/repro/issue_enum_increment_type_error.php';
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
        $this->assertStringContainsString('TypeError:Cannot increment E', $text);
        $this->assertStringContainsString('TypeError:Cannot decrement E', $text);
    }

}
