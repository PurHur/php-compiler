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

    public function testGetoptRestIndexByRefAfterArgvSeparator(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $vm = $repoRoot.'/bin/vm.php';
        $script = $repoRoot.'/test/repro/maintainer_gap_getopt_rest_index_byref_fatal.php';
        $this->assertFileExists($vm);
        $this->assertFileExists($script);

        $cmd = \sprintf(
            '%s %s %s -- -a1 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($vm),
            \escapeshellarg($script)
        );
        $stdout = shell_exec('cd '.\escapeshellarg($repoRoot).' && '.$cmd);
        $this->assertIsString($stdout);
        $this->assertStringContainsString("ok\n", $stdout);
    }

    /** Issue #9093 — optional long `opt::` with `--opt=value` must not hang script-file getopt(). */
    public function testGetoptOptionalLongEqualsSyntax(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $vm = $repoRoot.'/bin/vm.php';
        $script = $repoRoot.'/test/repro/maintainer_getopt_cli.php';
        $this->assertFileExists($vm);
        $this->assertFileExists($script);

        $cmd = \sprintf(
            '%s %s %s -a -b B --long L --opt=V -- -x 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($vm),
            \escapeshellarg($script)
        );
        $stdout = shell_exec('cd '.\escapeshellarg($repoRoot).' && '.$cmd);
        $this->assertIsString($stdout);
        $this->assertStringContainsString("'a' => false", $stdout);
        $this->assertStringContainsString("'b' => 'B'", $stdout);
        $this->assertStringContainsString("'long' => 'L'", $stdout);
        $this->assertStringContainsString("'opt' => 'V'", $stdout);
    }

    /** Issue #25144 — named rest_index with omitted long_options + Reflection defaults. */
    public function testGetoptNamedRestIndexAndReflection(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $vm = $repoRoot.'/bin/vm.php';
        $script = $repoRoot.'/test/repro/issue_25144_getopt_named_rest_index.php';
        $this->assertFileExists($vm);
        $this->assertFileExists($script);

        $cmd = \sprintf(
            '%s %s %s 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($vm),
            \escapeshellarg($script)
        );
        $stdout = shell_exec('cd '.\escapeshellarg($repoRoot).' && '.$cmd);
        $this->assertIsString($stdout);
        $this->assertStringContainsString("ri=1\n", $stdout);
        $this->assertStringNotContainsString('resolveIndirect()', $stdout);
        $this->assertStringContainsString('rest_index OPT REF=NULL', $stdout);
        $this->assertStringContainsString('argc=3 req=1', $stdout);
    }
}
