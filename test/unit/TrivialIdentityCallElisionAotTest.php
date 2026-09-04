<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Single-param identity user calls are replaced by the argument (#36386).
 *
 * @group aot-lint
 */
final class TrivialIdentityCallElisionAotTest extends TestCase
{
    public function testIdentityCallOmitsCallInHotLoop(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function id(int $x): int {
            return $x;
        }
        function work(int $n): int {
            $s = 0;
            for ($i = 0; $i < $n; ++$i) {
                $s += id($i);
            }
            return $s;
        }
        echo work(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_trivial_id_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_trivial_id_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $fnStart = strpos($ll, 'define i64 @work(i64)');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertStringNotContainsString('call i64 @id(', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['45'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testNonIdentityStillCalls(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function bump(int $x): int {
            return $x + 1;
        }
        function work(int $n): int {
            return bump($n);
        }
        echo work(3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_trivial_id_neg_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_trivial_id_neg_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            $fnStart = strpos($ll, 'define i64 @work(i64)');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertStringContainsString('call i64 @bump(', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['4'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
