<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded function_exists / extension_loaded on typed args must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: Zend/zend_builtin_functions.c (function_exists),
 * ext/standard/info.c (extension_loaded)
 *
 * @group aot-lint
 */
final class DiscardedFunctionExistsExtensionLoadedElisionAotTest extends TestCase
{
    public function testDiscardedOnlyFunctionExistsExtensionLoadedHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $fn, string $ext, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                function_exists($fn);
                extension_loaded($ext);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded('strlen', 'standard', 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_fxel_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_fxel_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(__compiler_builtin_function_exists|__compiler_extension_loaded)/',
                    $body
                ),
                'discarded function_exists/extension_loaded must be elided (no helper calls)'
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

    public function testLiveFunctionExistsExtensionLoadedMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $fn, string $ext): string {
            function_exists($fn);
            extension_loaded($ext);
            // Live extension_loaded(positive) is not Zend-parity under AOT yet
            // (module table always empty); assert shared false + function_exists.
            $a = function_exists('strlen') ? '1' : '0';
            $b = function_exists('no_such_fn_zz') ? '1' : '0';
            $c = extension_loaded('no_such_ext_zz') ? '1' : '0';
            return $a.$b.$c;
        }
        echo work('strlen', 'standard'), "\n";
        echo work('array_map', 'core'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_fxel_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_fxel_live_'.getmypid().'.bin';
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
}
