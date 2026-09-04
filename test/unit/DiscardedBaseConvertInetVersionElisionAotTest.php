<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded base_convert / ip2long / long2ip / version_compare on typed args
 * must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/math.c (base_convert), basic_functions.c (ip2long/
 * long2ip), versioning.c (version_compare)
 *
 * @group aot-lint
 */
final class DiscardedBaseConvertInetVersionElisionAotTest extends TestCase
{
    public function testDiscardedOnlyBaseConvertInetVersionHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $hex, string $ip, string $v, int $n, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                base_convert($hex, 16, 10);
                ip2long($ip);
                long2ip($n);
                version_compare($v, $v);
                version_compare($v, '8.1.0', '>=');
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('ff', '127.0.0.1', '8.2.0', 42, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_bciv_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_bciv_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(phpc_base_convert|__compiler_ip2long|__compiler_long2ip|__compiler_version_compare)\b/',
                    $body
                ),
                'discarded base_convert/ip2long/long2ip/version_compare must be elided (no helper calls)'
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

    public function testLiveBaseConvertInetVersionMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $hex, string $ip, string $v, int $n): string {
            // Discarded typed forms (elision under test).
            base_convert($hex, 16, 10);
            ip2long($ip);
            long2ip($n);
            version_compare($v, $v);
            version_compare($v, '8.1.0', '>=');
            // Live results use literals where typed-string AOT helpers segfault
            // on master (peer hexdec note in DiscardedBaseConvertPiDebugTypeElisionAotTest).
            $bc = base_convert('ff', 16, 10);
            $i2 = ip2long('127.0.0.1');
            $l2 = long2ip(42);
            $vc = version_compare('8.2.0', '8.2.0');
            $vo = version_compare('8.2.0', '8.1.0', '>=') ? '1' : '0';
            return $bc.'|'.$i2.'|'.$l2.'|'.$vc.'|'.$vo;
        }
        echo work('ff', '127.0.0.1', '8.2.0', 42), "\n";
        echo work('10', '0.0.0.0', '7.4.0', 0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_bciv_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bciv_live_'.getmypid().'.bin';
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
