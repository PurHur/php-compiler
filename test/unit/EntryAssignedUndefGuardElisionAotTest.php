<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Entry-prologue assigns make later undef-var guards redundant (#36386).
 *
 * @group aot-lint
 */
final class EntryAssignedUndefGuardElisionAotTest extends TestCase
{
    public function testCountedForLoopOmitsUndefVarBranches(): void
    {
        $src = <<<'PHP'
        <?php
        function simple(): int {
            $a = 0;
            for ($i = 0; $i < 3; ++$i) {
                ++$a;
            }
            return $a;
        }
        echo simple(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_entry_undef_elide_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_entry_undef_elide_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            $fnStart = strpos($ll, 'define i64 @simple(');
            $this->assertNotFalse($fnStart, 'missing @simple');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertStringNotContainsString(
                'undef_var_warn',
                $body,
                'entry-assigned $i/$a must not emit per-iteration undef guards (#36386)'
            );
            $this->assertStringNotContainsString('__compiler_trigger_error', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['3'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testSecondForInitDominatingAssignOmitsUndefVarBranches(): void
    {
        $src = <<<'PHP'
        <?php
        function simple(): int {
            $a = 0;
            for ($i = 0; $i < 2; ++$i) {
                ++$a;
            }
            $b = 0;
            for ($j = 0; $j < 3; ++$j) {
                ++$b;
            }
            return $a + $b;
        }
        echo simple(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_second_for_undef_elide_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_second_for_undef_elide_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            $fnStart = strpos($ll, 'define i64 @simple(');
            $this->assertNotFalse($fnStart, 'missing @simple');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertStringNotContainsString(
                'undef_var_warn',
                $body,
                'mid-function for-init that dominates the loop header must elide undef guards (#36386)'
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['5'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testConditionalAssignStillWarnsOnMaybeUndefined(): void
    {
        $src = <<<'PHP'
        <?php
        function f(bool $c): void {
            if ($c) {
                $x = 1;
            }
            echo @$x, "\n";
        }
        f(false);
        f(true);
        PHP;
        $path = sys_get_temp_dir().'/phpc_entry_undef_cond_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_entry_undef_cond_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1';
            exec($zendCmd, $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));

            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aotOut));
            $this->assertSame(implode("\n", $zendOut), implode("\n", $aotOut));
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
