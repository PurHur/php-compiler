<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * #34635 — foreach must `__string__separate` HT string keys before writeString.
 *
 * php-src: Zend/zend_execute.c — ZEND_FE_FETCH_R array branch
 *          ext/spl/spl_array.c — ArrayObject/ArrayIterator HT walk
 */
final class Issue34635ForeachStringKeyBorrowAotTest extends TestCase
{
    public function testCompileKeyHashtableSeparatesNodeKey(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/VM/VmIteratorForeach.php'
        );
        $this->assertMatchesRegularExpression(
            '/function compileKeyHashtable.*?__string__separate.*?__value__writeString/s',
            $src
        );
        $this->assertStringContainsString('#34635', $src);
    }

    public function testEmptyBodyVmMatchesZend(): void
    {
        $this->assertBackendMatchesZend('vm', 'aot_foreach_string_key_borrow.php');
    }

    public function testEmptyBodyAotMatchesZend(): void
    {
        $this->assertBackendMatchesZend('aot', 'aot_foreach_string_key_borrow.php');
    }

    public function testArrayObjectAotMatchesZend(): void
    {
        $this->assertBackendMatchesZend('aot', 'maintainer_gap_aot_arrayobject_foreach.php');
    }

    private function assertBackendMatchesZend(string $backend, string $reproBasename): void
    {
        $path = __DIR__.'/../repro/'.$reproBasename;
        $zend = $this->runPhp((string) file_get_contents($path));
        $this->assertNotSame('', $zend, 'zend fixture empty');

        if ('vm' === $backend) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'fe_34635.php');
            ob_start();
            try {
                $runtime->run($block);
            } catch (\PHPCompiler\VM\ScriptExit $e) {
            }
            $this->assertSame($zend, ob_get_clean(), 'VM vs Zend');

            return;
        }

        $bin = sys_get_temp_dir().'/phpc_34635_'.md5($path.$backend).'.bin';
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
        $this->assertFileExists($bin);
        $lines = [];
        exec(escapeshellarg($bin).' 2>&1', $lines, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, 'AOT exited '.$rc.': '.implode("\n", $lines));
        $this->assertSame($zend, implode("\n", $lines).(count($lines) ? "\n" : ''), 'AOT vs Zend');
    }

    private function runPhp(string $code): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'z34635');
        file_put_contents($tmp, $code);
        $lines = [];
        exec('php '.escapeshellarg($tmp).' 2>&1', $lines, $rc);
        @unlink($tmp);
        $this->assertSame(0, $rc, 'zend exited '.$rc);

        return implode("\n", $lines).(count($lines) ? "\n" : '');
    }
}
