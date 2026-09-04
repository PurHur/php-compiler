<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * strlen() on literals / native strings skips after-call throw-pending (#36386).
 *
 * @group aot-lint
 */
final class NoThrowStrlenBuiltinElisionAotTest extends TestCase
{
    public function testStrlenLiteralOmitsThrowPendingInHotLoop(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            $s = 0;
            for ($i = 0; $i < $n; ++$i) {
                $s += strlen('x');
            }
            return $s;
        }
        echo work(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_strlen_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_strlen_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('phpc_ex_stack_push', $body);
            $this->assertStringNotContainsString('phpc_ex_stack_pop', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);
            $this->assertStringNotContainsString('phpc_jit_abort_if_pending_type_error', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['10'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testStrlenObjectStillChecksThrowPending(): void
    {
        $src = <<<'PHP'
        <?php
        class Boom {
            public function __toString(): string {
                throw new Exception('boom');
            }
        }
        function work($o): int {
            return strlen($o);
        }
        echo work(new Boom()), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_strlen_obj_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_strlen_obj_'.getmypid().'.bin';
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
            $fnStart = strpos($ll, 'define i64 @work(');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            // Object arg may throw from __toString — must not take the pure-string elision.
            // (Runtime catch of throwing __toString still SIGSEGVs on master; IR only.)
            $this->assertTrue(
                str_contains($body, 'phpc_jit_has_throw_pending')
                    || str_contains($body, 'phpc_ex_stack_push')
                    || str_contains($body, '__toString')
                    || str_contains($body, 'toString')
                    || str_contains($body, 'phpc_jit_abort_if_pending_type_error'),
                'object strlen must retain throw / toString / type-error path'
            );
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}