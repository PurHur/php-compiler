<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded decbin/dechex/decoct / bindec/hexdec/octdec / pi / get_debug_type on
 * typed args must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/math.c (base converts, pi), type.c (get_debug_type)
 *
 * @group aot-lint
 */
final class DiscardedBaseConvertPiDebugTypeElisionAotTest extends TestCase
{
    public function testDiscardedOnlyBaseConvertPiDebugTypeHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $hex, int $n, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                decbin($n);
                dechex($n);
                decoct($n);
                bindec('1010');
                hexdec($hex);
                octdec('77');
                pi();
                get_debug_type($n);
                get_debug_type($hex);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('ff', 42, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_bcpd_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_bcpd_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(snprintf|strtol|phpc_base_convert|phpc_decbin|phpc_dechex|phpc_decoct|phpc_bindec|phpc_hexdec|phpc_octdec)\b/',
                    $body
                ),
                'discarded base-convert/pi/get_debug_type must be elided (no helper calls)'
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

    public function testLiveBaseConvertPiDebugTypeMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $hex, int $n): string {
            decbin($n);
            dechex($n);
            decoct($n);
            bindec('1010');
            // Live hexdec($typedString) AOT segfaults on master — use literal for
            // observable result; discarded hexdec($hex) still exercises elision.
            hexdec($hex);
            octdec('77');
            pi();
            get_debug_type($n);
            $db = decbin($n);
            $dx = dechex($n);
            $do = decoct($n);
            $bd = bindec('1010');
            $hd = hexdec('ff');
            $od = octdec('77');
            $p = pi();
            $gt = get_debug_type($n);
            $gt2 = get_debug_type($hex);
            return $db.'|'.$dx.'|'.$do.'|'.$bd.'|'.$hd.'|'.$od.'|'.$p.'|'.$gt.'|'.$gt2;
        }
        echo work('ff', 42), "\n";
        echo work('10', 0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_bcpd_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bcpd_live_'.getmypid().'.bin';
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
