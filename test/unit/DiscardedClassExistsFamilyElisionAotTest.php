<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded class_exists / interface_exists / trait_exists / enum_exists with
 * compile-time-false $autoload must not lower (#36386). Live results still
 * match Zend.
 *
 * php-src: Zend/zend_builtin_functions.c
 *
 * @group aot-lint
 */
final class DiscardedClassExistsFamilyElisionAotTest extends TestCase
{
    public function testDiscardedOnlyClassExistsFamilyHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $c, string $i, string $t, string $e, int $loops): int {
            $sum = 0;
            for ($n = 0; $n < $loops; ++$n) {
                class_exists($c, false);
                interface_exists($i, false);
                trait_exists($t, false);
                enum_exists($e, false);
                $sum += $n;
            }
            return $sum;
        }
        echo only_discarded('stdClass', 'Traversable', 'NoSuchTrait36386', 'NoSuchEnum36386', 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_cef_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_cef_only_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@only_discarded\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @only_discarded');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertSame(
                0,
                preg_match_all(
                    '/call [^\n]*@(__phpc_jit_class_exists|__phpc_jit_interface_exists|__phpc_jit_trait_exists|__phpc_jit_enum_exists)/',
                    $body
                ),
                'discarded class_exists family with autoload=false must be elided'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
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

    public function testLiveClassExistsFamilyMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $c): string {
            class_exists($c, false);
            interface_exists('Traversable', false);
            // Live lookups — autoload=false so no side effects.
            $a = class_exists('stdClass', false) ? '1' : '0';
            $b = class_exists('NoSuchClass36386', false) ? '1' : '0';
            $c = interface_exists('Traversable', false) ? '1' : '0';
            $d = trait_exists('NoSuchTrait36386', false) ? '1' : '0';
            $e = enum_exists('NoSuchEnum36386', false) ? '1' : '0';
            return $a.$b.$c.$d.$e;
        }
        echo work('stdClass'), "\n";
        echo work('NoSuchClass36386'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_cef_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cef_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut, 'live results must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDefaultAutoloadClassExistsStaysLiveInIr(): void
    {
        // Default $autoload=true must not be elided (spl_autoload side effects).
        // Do not execute the binary: AOT class_exists with default autoload still
        // requires an active VM context in ClassExistsJitHelper (#36386 scope).
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function keep_live(string $c, int $loops): int {
            $sum = 0;
            for ($n = 0; $n < $loops; ++$n) {
                class_exists($c);
                $sum += $n;
            }
            return $sum;
        }
        echo keep_live('stdClass', 4), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_cef_autoload_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cef_autoload_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@keep_live\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @keep_live');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertGreaterThan(
                0,
                preg_match_all('/call [^\n]*@__phpc_jit_class_exists/', $body),
                'class_exists without false autoload must stay live'
            );
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
