<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Untyped for-loop CVs (`inferred:unknown`) must stay native i64 under AOT (#36386).
 *
 * @group aot-lint
 */
final class NativeLongLoopCvAotTest extends TestCase
{
    public function testSimplecallLoopCounterAvoidsValueBoxMegapath(): void
    {
        $src = <<<'PHP'
        <?php
        function simplecall(): void {
            for ($i = 0; $i < 3; ++$i) {
                strlen('hallo');
            }
        }
        simplecall();
        echo "Done\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_native_long_loop_cv_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_native_long_loop_cv_'.getmypid().'.bin';
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
            $fnStart = strpos($ll, 'define void @simplecall()');
            $this->assertNotFalse($fnStart, 'missing @simplecall');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertStringContainsString('alloca i64', $body);
            // Loop header must not cast a boxed __value__ counter through the megapath.
            $this->assertStringNotContainsString('int_cast_value_null', $body);
            $this->assertStringNotContainsString('__value__writeLong', $body);
            $this->assertStringNotContainsString('incdec_vbox', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['Done'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testCountedLoopAccumulatorMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        function simple(): int {
            $a = 0;
            for ($i = 0; $i < 1000; ++$i) {
                ++$a;
            }
            return $a;
        }
        echo simple(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_native_long_acc_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_native_long_acc_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['1000'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
