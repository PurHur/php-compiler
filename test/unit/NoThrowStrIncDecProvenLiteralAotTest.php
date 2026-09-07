<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * str_increment()/str_decrement() with a proven-safe compile-time literal fold
 * to an immortal string and skip after-call throw-pending (#36386).
 * php-src ext/standard/string.c PHP_FUNCTION(str_increment|str_decrement).
 *
 * @group aot-lint
 */
final class NoThrowStrIncDecProvenLiteralAotTest extends TestCase
{
    public function testProvenLiteralFoldsAndOmitsThrowPending(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsStrIncrement()) {
            $this->markTestSkipped('str_increment/str_decrement not registered on this profile');
        }
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): string {
            return str_increment('a9') . str_decrement('b');
        }
        echo work(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_strincdec_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_strincdec_'.getmypid().'.bin';
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

            $fnStart = strpos($ll, 'define %__string__* @work()');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            // Proven literals fold — no helper bridge, no ValueError / throw-pending.
            $this->assertStringNotContainsString('phpc_str_increment', $body);
            $this->assertStringNotContainsString('phpc_str_decrement', $body);
            $this->assertStringNotContainsString('str_increment_bridge', $body);
            $this->assertStringNotContainsString('str_decrement_bridge', $body);
            $this->assertStringNotContainsString('ValueError', $body);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            // 'a9'→'b0', 'b'→'a'
            $this->assertSame(['b0a'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeTypedStringKeepsHelper(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsStrIncrement()) {
            $this->markTestSkipped('str_increment/str_decrement not registered on this profile');
        }
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s): string {
            return str_increment($s);
        }
        echo work('a'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_strincdec_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_strincdec_rt_'.getmypid().'.bin';
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

            $fnStart = strpos($ll, 'define %__string__* @work(%__string__*');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('phpc_str_increment', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['b'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
