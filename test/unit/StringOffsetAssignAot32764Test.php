<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT string offset assign must mutate the string, not convert to array (#32764).
 *
 * @group llvm
 * @group aot
 */
final class StringOffsetAssignAot32764Test extends TestCase
{
    public function testStringOffsetAssignMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_string_offset_assign_aot.php';
        $zend = $this->runCommandStdout([PHP_BINARY, $source], $root);
        $this->assertSame("aZc\nstring\n", $zend);

        $vm = $this->runCommandStdout([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($zend, $vm);

        $out = $root.'/build/test-aot-string-offset-assign-32764';
        @mkdir(dirname($out), 0775, true);
        $this->runCommandStdout(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $aot = $this->runCommandStdout([$out], $root);
        $this->assertSame($zend, $aot, 'AOT stdout must match Zend for string offset assign');
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
