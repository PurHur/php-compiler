<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * #34628 — AOT GlobIterator snapshot must NestedJIT `\glob()` directly
 * (VmFsGlob::glob returns null under NestedJIT → array_values TypeError).
 *
 * php-src: ext/spl/spl_directory.c — GlobIterator
 * Peer: DirectoryIteratorSnapshotJitHelper #33263
 */
final class Issue34628GlobIteratorNestedJitGlobAotTest extends TestCase
{
    public function testSnapshotHelperCallsGlobDirectly(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../ext/spl/GlobIteratorSnapshotJitHelper.php'
        );
        $this->assertStringContainsString('\\glob($pattern, $globFlags)', $src);
        $this->assertStringContainsString('#34628', $src);
        // Call site only — docblocks may still name VmFsGlob as the forbidden path.
        $this->assertStringNotContainsString('VmFsGlob::glob(', $src);
    }

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
        $path = __DIR__.'/../repro/globiterator_aot_foreach.php';
        $zend = $this->runPhp((string) file_get_contents($path));
        $this->assertMatchesRegularExpression('/^2\na\.txt,b\.txt\n0\n$/', $zend, 'zend fixture');

        if ('vm' === $backend) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'gi_34628.php');
            ob_start();
            try {
                $runtime->run($block);
            } catch (\PHPCompiler\VM\ScriptExit $e) {
            }
            $this->assertSame($zend, ob_get_clean(), 'VM vs Zend');

            return;
        }

        $bin = sys_get_temp_dir().'/phpc_34628_'.md5($path).'.bin';
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
        $aot = shell_exec(escapeshellarg($bin).' 2>&1');
        @unlink($bin);
        $this->assertSame($zend, (string) $aot, 'AOT vs Zend');
    }

    private function runPhp(string $code): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'z34628');
        file_put_contents($tmp, $code);
        $out = (string) shell_exec('php '.escapeshellarg($tmp).' 2>&1');
        @unlink($tmp);

        return $out;
    }
}
