<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `echo $undef ?? default` must compile and match Zend ZEND_COALESCE (#32445).
 *
 * php-src: Zend/zend_vm_def.h ZEND_COALESCE — undefined CV is IS_UNDEF, no notice.
 */
final class CoalesceUndefEcho32445AotTest extends TestCase
{
    public function testUndefCoalesceEchoAotMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_32445_coalesce_undef_echo.php';
        $expected = "d\nn\nok\n";

        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($expected, $vmOut, 'VM ?? on undefined must match Zend');

        $out = $root.'/build/test-aot-coalesce-undef-echo-32445';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($expected, $aotOut, 'AOT echo $undef ?? default must match VM');
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
