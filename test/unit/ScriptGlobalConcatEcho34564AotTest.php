<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * #34564 — encapsed echo of script-global after .= then another local .=
 * must not SIGSEGV under AOT (strval valueToString null-inits value box).
 */
final class ScriptGlobalConcatEcho34564AotTest extends TestCase
{
    public function testVmMatchesZend(): void
    {
        $this->assertBackendMatchesZend('vm');
    }

    public function testAotMatchesZend(): void
    {
        $this->assertBackendMatchesZend('aot');
    }

    private function assertBackendMatchesZend(string $backend): void
    {
        $path = __DIR__ . '/../repro/issue_34564_script_global_concat_echo_aot.php';
        $zend = $this->runPhp(file_get_contents($path));
        if ('vm' === $backend) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(file_get_contents($path), 't.php');
            ob_start();
            try {
                $runtime->run($block);
            } catch (\PHPCompiler\VM\ScriptExit $e) {
            }
            $this->assertSame($zend, ob_get_clean(), 'VM vs Zend');

            return;
        }
        $bin = sys_get_temp_dir() . '/phpc_34564_' . md5($path) . '.bin';
        $proc = proc_open(
            ['php', 'bin/compile.php', '-o', $bin, $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), "compile failed: $err");
        $aot = shell_exec(escapeshellarg($bin) . ' 2>&1');
        @unlink($bin);
        $this->assertSame($zend, (string) $aot, 'AOT vs Zend');
    }

    private function runPhp(string $code): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'z34564');
        file_put_contents($tmp, $code);
        $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);

        return $out;
    }
}
