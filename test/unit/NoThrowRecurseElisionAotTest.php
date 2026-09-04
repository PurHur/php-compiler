<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Leaf-recursive no-throw callees skip phpc_ex_stack_push/pop + throw-pending (#36386).
 *
 * @group aot-lint
 */
final class NoThrowRecurseElisionAotTest extends TestCase
{
    public function testFiboROmitsExceptionStackAndPendingThrowChecks(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function fibo_r(int $n): int {
            return ($n < 2) ? 1 : fibo_r($n - 2) + fibo_r($n - 1);
        }
        echo fibo_r(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_fibo_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_fibo_'.getmypid().'.bin';
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
            $fnStart = strpos($ll, 'define i64 @fibo_r(i64)');
            $this->assertNotFalse($fnStart, 'missing @fibo_r');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertStringContainsString('call i64 @fibo_r', $body);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $body);
            $this->assertStringNotContainsString('phpc_ex_stack_pop', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);
            $this->assertStringNotContainsString('phpc_jit_abort_if_pending_type_error', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['89'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRecursiveThrowStillPropagatesUncaught(): void
    {
        $src = <<<'PHP'
        <?php
        function boom(int $n): void {
            if ($n <= 0) {
                throw new Exception('x');
            }
            boom($n - 1);
        }
        boom(3);
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_boom_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_boom_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(255, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString('Uncaught Exception', $joined);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testLeafMethodCallOmitsExceptionStackWhenEnqueuedBeforeMain(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        final class Node {
            public int $value;
            public function __construct(int $v) { $this->value = $v; }
            public function bump(): int { return ++$this->value; }
        }
        $sum = 0;
        $n = new Node(0);
        for ($i = 0; $i < 10; ++$i) {
            $sum += $n->bump();
        }
        echo $sum, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_bump_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_bump_'.getmypid().'.bin';
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
            $callPos = strpos($ll, 'call i64 @Node__bump');
            $this->assertNotFalse($callPos, 'missing call to @Node__bump');
            // Window around the call site in {main} / internal_* — not the method body.
            $window = substr($ll, max(0, $callPos - 400), 800);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $window);
            $this->assertStringNotContainsString('phpc_ex_stack_pop', $window);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $window);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['55'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
