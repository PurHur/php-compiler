<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ??= and `$t = $x ?? …` must echo named locals (script-global __value__**, #24009).
 *
 * php-src: Zend/zend_compile.c (ZEND_COALESCE / ASSIGN_OP), zend_vm_def.h ZEND_COALESCE.
 */
final class NullCoalesceAssignAotTest extends TestCase
{
    public function testNullCoalesceAssignAotMatchesVmAndZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_24009_nullcoalesce_assign_aot.php';
        $expected = "set\nkeep\nfrom-temp\n";

        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($expected, $vmOut, 'VM ??= must match Zend');

        $out = $root.'/build/test-aot-nullcoalesce-assign-24009';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($expected, $aotOut, 'AOT ??= must match VM (script-global echo load)');
    }

    /**
     * @param list<string> $cmd
     */
    private function runCommand(array $cmd, string $cwd, int $expectExit = 0): string
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
