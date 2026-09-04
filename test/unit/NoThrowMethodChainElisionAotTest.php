<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Same-class method call chains skip phpc_ex_stack_push/pop (#36386).
 *
 * @group aot-lint
 */
final class NoThrowMethodChainElisionAotTest extends TestCase
{
    public function testMethodChainOmitsExceptionStackAfterFixpoint(): void
    {
        // Reverse declaration order: mid before leaf — fixpoint must upgrade mid/top.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        final class A {
            public function mid(int $x): int { return $this->leaf($x) + 1; }
            public function leaf(int $x): int { return $x + 1; }
            public function top(int $x): int { return $this->mid($x) + 1; }
        }
        $o = new A();
        $s = 0;
        for ($i = 0; $i < 10; ++$i) {
            $s += $o->top(1);
        }
        echo $s, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_mchain_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_mchain_'.getmypid().'.bin';
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

            foreach (['A__leaf', 'A__mid', 'A__top'] as $name) {
                $fnStart = strpos($ll, 'define i64 @'.$name.'(');
                $this->assertNotFalse($fnStart, 'missing @'.$name);
                $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
                $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
                $this->assertStringNotContainsString('phpc_ex_stack_push', $body, $name.' body');
                $this->assertStringNotContainsString('phpc_ex_stack_pop', $body, $name.' body');
                $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body, $name.' body');
            }

            $callPos = strpos($ll, 'call i64 @A__top');
            $this->assertNotFalse($callPos, 'missing call to @A__top');
            $window = substr($ll, max(0, $callPos - 400), 800);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $window);
            $this->assertStringNotContainsString('phpc_ex_stack_pop', $window);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $window);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            // top(1)=mid(1)+1=leaf(1)+1+1=4; ×10 → 40
            $this->assertSame(['40'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testThrowingMethodCalleeStillKeepsExceptionStackOnCaller(): void
    {
        // Runtime: method `throw` currently exits 0 with no uncaught printer on master
        // as well — assert only that wrap→boom is not no-throw-elided (IR).
        $src = <<<'PHP'
        <?php
        final class B {
            public function boom(): void { throw new Exception('x'); }
            public function wrap(): void { $this->boom(); }
        }
        (new B())->wrap();
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_mboom_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_mboom_'.getmypid().'.bin';
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
            $callPos = strpos($ll, 'call void @B__boom');
            if (false === $callPos) {
                $callPos = strpos($ll, 'call i64 @B__boom');
            }
            $this->assertNotFalse($callPos, 'missing call to @B__boom');
            $window = substr($ll, max(0, $callPos - 600), 1200);
            $this->assertStringContainsString('phpc_ex_stack_push', $window);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
