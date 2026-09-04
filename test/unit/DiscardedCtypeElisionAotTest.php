<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ctype_* AOT: typed string formals must match Zend; discarded typed-string
 * calls may elide (#36386). php-src: ext/ctype/ctype.c
 *
 * @group aot-lint
 */
final class DiscardedCtypeElisionAotTest extends TestCase
{
    public function testCtypeDigitOnStringFormalMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s): int {
            return (int) ctype_digit($s) + (int) ctype_alnum($s) + (int) ctype_alpha($s);
        }
        echo work('123'), "\n";
        echo work('Ab9'), "\n";
        echo work('abc'), "\n";
        echo work('   '), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_ctype_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_ctype_formal_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut, 'AOT must match Zend for string formals');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDiscardedCtypeOnStringFormalAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                ctype_digit($s);
                ctype_alnum($s);
                ctype_alpha($s);
                ctype_space($s);
                $c += $k;
            }
            return $c + (int) ctype_digit($s) + (int) ctype_alnum('Ab9');
        }
        echo work('123', 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_ctype_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_ctype_'.getmypid().'.bin';
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

            $sig = null;
            if (preg_match('/define [^\n]*@work\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @work');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringNotContainsString('<badref>', $body, 'ctype lowering must not orphan insert block');
            // Call-site LLVM must be used (not the NestedJIT helper ABI).
            $this->assertSame(
                0,
                preg_match_all('/CtypeJitHelper__checkstring|__phpc_ctype_check_string/', $body),
                'typed-string ctype must use CtypeCheckLlvm, not NestedJIT helper'
            );
            // Discarded elision is covered by DiscardedPureCallElisionTest (Internal call).
            // AOT may still lower discarded ctype when resolveFunctionProxy wraps the builtin.

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDiscardedCtypeOnIntStaysLiveForDeprecation(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                ctype_digit($n);
                $c += $k;
            }
            return $c;
        }
        echo work(5, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_ctype_int_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_ctype_int_'.getmypid().'.bin';
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

            $sig = null;
            if (preg_match('/define [^\n]*@work\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @work');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringNotContainsString('<badref>', $body);
            $this->assertGreaterThanOrEqual(
                1,
                preg_match_all('/ctype_int_/', $body)
                    + preg_match_all('/Argument of type int will be interpreted/', $body),
                'discarded ctype_digit(int) must stay live for deprecation'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $this->assertSame('3', $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
