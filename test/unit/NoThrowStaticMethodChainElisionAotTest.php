<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Same-class static call chains skip phpc_ex_stack_push/pop (#36386).
 *
 * @group aot-lint
 */
final class NoThrowStaticMethodChainElisionAotTest extends TestCase
{
    public function testStaticSelfChainOmitsExceptionStackAfterFixpoint(): void
    {
        // Reverse declaration order: mid before leaf — fixpoint must upgrade mid/top.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        final class A {
            public static function mid(int $x): int { return self::leaf($x) + 1; }
            public static function leaf(int $x): int { return $x + 1; }
            public static function top(int $x): int { return self::mid($x) + 1; }
        }
        $s = 0;
        for ($i = 0; $i < 10; ++$i) {
            $s += A::top(1);
        }
        echo $s, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_schain_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_schain_'.getmypid().'.bin';
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

    public function testThrowingStaticCalleeStillKeepsExceptionStackOnCaller(): void
    {
        $src = <<<'PHP'
        <?php
        final class B {
            public static function boom(): void { throw new Exception('x'); }
            public static function wrap(): void { self::boom(); }
        }
        B::wrap();
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_sboom_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_sboom_'.getmypid().'.bin';
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

    public function testCrossClassStaticNameDoesNotUnlockCaller(): void
    {
        // B::leaf throws; A::mid must not treat bare "leaf" as safe via B's key.
        $src = <<<'PHP'
        <?php
        final class B {
            public static function leaf(int $x): int { throw new Exception('x'); }
        }
        final class A {
            public static function leaf(int $x): int { return $x + 1; }
            public static function mid(int $x): int { return self::leaf($x) + 1; }
        }
        echo A::mid(1), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_scross_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_scross_'.getmypid().'.bin';
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

            $fnStart = strpos($ll, 'define i64 @A__mid(');
            $this->assertNotFalse($fnStart, 'missing @A__mid');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            // A::leaf is no-throw; mid→self::leaf should elide once leaf is proven.
            $this->assertStringNotContainsString('phpc_ex_stack_push', $body, 'A__mid body');

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            // mid(1)=leaf(1)+1=3
            $this->assertSame(['3'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
