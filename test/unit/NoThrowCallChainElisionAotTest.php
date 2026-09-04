<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No-throw call chains (top→mid→leaf) skip phpc_ex_stack_push/pop (#36386).
 *
 * @group aot-lint
 */
final class NoThrowCallChainElisionAotTest extends TestCase
{
    public function testCallChainOmitsExceptionStackEvenIfCalleeDeclaredLater(): void
    {
        // Reverse declaration order: mid before leaf — fixpoint must upgrade mid.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function mid(int $n): int { return leaf($n) + leaf($n + 1); }
        function leaf(int $n): int { return $n + 1; }
        function top(int $n): int {
            $s = 0;
            for ($i = 0; $i < $n; ++$i) {
                $s += mid($i);
            }
            return $s;
        }
        echo top(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_chain_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_chain_'.getmypid().'.bin';
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

            foreach (['leaf', 'mid', 'top'] as $name) {
                $fnStart = strpos($ll, 'define i64 @'.$name.'(i64)');
                $this->assertNotFalse($fnStart, 'missing @'.$name);
                $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
                $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
                $this->assertStringNotContainsString('phpc_ex_stack_push', $body, $name.' body');
                $this->assertStringNotContainsString('phpc_ex_stack_pop', $body, $name.' body');
                $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body, $name.' body');
            }

            $callPos = strpos($ll, 'call i64 @top');
            $this->assertNotFalse($callPos, 'missing call to @top');
            $window = substr($ll, max(0, $callPos - 400), 800);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $window);
            $this->assertStringNotContainsString('phpc_ex_stack_pop', $window);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $window);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            // mid(i)=2*i+3 for i=0..9 → sum = 120
            $this->assertSame(['120'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testThrowingCalleeStillKeepsExceptionStackOnCaller(): void
    {
        $src = <<<'PHP'
        <?php
        function boom(): void { throw new Exception('x'); }
        function wrap(): void { boom(); }
        wrap();
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_chain_boom_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_chain_boom_'.getmypid().'.bin';
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
            $callPos = strpos($ll, 'call void @boom');
            if (false === $callPos) {
                $callPos = strpos($ll, 'call i64 @boom');
            }
            $this->assertNotFalse($callPos, 'missing call to @boom');
            $window = substr($ll, max(0, $callPos - 600), 1200);
            $this->assertStringContainsString('phpc_ex_stack_push', $window);
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(255, $runRc);
            $this->assertStringContainsString('Uncaught Exception', implode("\n", $runOut));
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
