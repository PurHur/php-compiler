<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT string offset reads must match VM byte values (#22646).
 *
 * Compares stdout only — warning text may differ slightly (index suffix).
 *
 * @group llvm
 * @group aot
 */
final class StringOffsetAotVmDifferentialTest extends TestCase
{
    public function testScriptGlobalOffsetReadsMatchVmStdout(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_22646_string_offset.php';
        $vmOut = $this->runCommandStdout([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame("A|3\nT\n0\n", $vmOut);

        $out = $root.'/build/test-aot-string-offset-22646';
        @mkdir(dirname($out), 0775, true);
        $this->runCommandStdout(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $aotOut = $this->runCommandStdout([$out], $root);
        $this->assertSame($vmOut, $aotOut, 'AOT stdout must match VM for string offset reads');
    }

    /**
     * @param list<string> $cmd
     */
    private function runCommandStdout(array $cmd, string $cwd, int $expectExit = 0): string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame($expectExit, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }
}
