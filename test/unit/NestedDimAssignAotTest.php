<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: nested `$g[1][0] = 9` must persist (ZEND_FETCH_DIM_W intermediate → live child HT, #24011).
 *
 * php-src: Zend/zend_execute.c (ZEND_FETCH_DIM_W / zend_fetch_dimension_address).
 */
final class NestedDimAssignAotTest extends TestCase
{
    public function testNestedIntDimAssignAotMatchesVmAndZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_24011_nested_dim_assign.php';
        $expected = "9\n";

        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($expected, $vmOut, 'VM nested dim assign must match Zend');

        $out = $root.'/build/test-aot-nested-dim-assign-24011';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($expected, $aotOut, 'AOT nested dim assign must persist into parent array');
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
