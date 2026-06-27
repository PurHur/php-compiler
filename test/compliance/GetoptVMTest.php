<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getopt() CLI parity (issue #3251). */
final class GetoptVMTest extends TestCase
{
    public function testGetoptMatchesZendShape(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $vm = $repoRoot.'/bin/vm.php';
        $script = $repoRoot.'/test/repro/maintainer-getopt.php';
        $this->assertFileExists($vm);
        $this->assertFileExists($script);

        $cmd = \sprintf(
            '%s %s %s -a -b BVAL --help --output=out.txt --output out2.txt 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($vm),
            \escapeshellarg($script)
        );
        $stdout = shell_exec('cd '.\escapeshellarg($repoRoot).' && '.$cmd);
        $this->assertIsString($stdout);
        $this->assertStringContainsString("'a' => false", $stdout);
        $this->assertStringContainsString("'b' => 'BVAL'", $stdout);
        $this->assertStringContainsString("'help' => false", $stdout);
        $this->assertStringContainsString("'output'", $stdout);
        $this->assertStringContainsString("'out.txt'", $stdout);
        $this->assertStringContainsString("'out2.txt'", $stdout);
    }

    public function testGetoptRestIndexByRef(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $vm = $repoRoot.'/bin/vm.php';
        $script = $repoRoot.'/test/repro/maintainer_gap_getopt_rest_index_byref_fatal.php';
        $this->assertFileExists($vm);
        $this->assertFileExists($script);

        $cmd = \sprintf(
            '%s %s %s -a 1 rest 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($vm),
            \escapeshellarg($script)
        );
        $stdout = shell_exec('cd '.\escapeshellarg($repoRoot).' && '.$cmd);
        $this->assertIsString($stdout);
        $this->assertStringContainsString("ok\n", $stdout);
    }
}
