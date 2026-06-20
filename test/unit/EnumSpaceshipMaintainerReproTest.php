<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #10203 / re-#9796 — backed enum <=> must match Zend (Zend/zend_enum.c).
 *
 * Guards maintainer repro: enum-vs-enum, enum-vs-scalar, enum-vs-string all return int(1)
 * except identical cases (int(0)).
 */
final class EnumSpaceshipMaintainerReproTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testVmMaintainerReproMatchesZend(): void
    {
        $repro = $this->repoRoot.'/test/repro/maintainer_spaceship_enum.php';
        $this->assertFileExists($repro);
        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->repoRoot.'/bin/vm.php'),
            escapeshellarg($repro)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame(
            "int(1)\nint(1)\nint(1)",
            implode("\n", array_values(array_filter($out, static fn (string $line): bool => str_starts_with($line, 'int('))))
        );
    }

    public function testJitMaintainerReproMatchesZend(): void
    {
        $repro = $this->repoRoot.'/test/repro/maintainer_spaceship_enum.php';
        $this->assertFileExists($repro);
        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->repoRoot.'/bin/jit.php'),
            escapeshellarg($repro)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame(
            "int(1)\nint(1)\nint(1)",
            implode("\n", array_values(array_filter($out, static fn (string $line): bool => str_starts_with($line, 'int('))))
        );
    }
}
