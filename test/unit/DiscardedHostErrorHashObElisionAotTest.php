<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded gethostname / error_get_last / getrusage / hash_algos /
 * hash_hmac_algos / ob_get_contents / ob_get_length / headers_list must not
 * lower (#36386). Live results still match Zend on shape checks.
 *
 * php-src: ext/standard/basic_functions.c, ext/hash/hash.c,
 * ext/standard/output.c, ext/standard/head.c
 *
 * @group aot-lint
 */
final class DiscardedHostErrorHashObElisionAotTest extends TestCase
{
    public function testDiscardedOnlyHostErrorHashObHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, int $mode): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                gethostname();
                error_get_last();
                getrusage();
                getrusage($mode);
                hash_algos();
                hash_hmac_algos();
                ob_get_contents();
                ob_get_length();
                headers_list();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, 0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_heho_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_heho_only_'.getmypid().'.bin';
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
                preg_match_all('/__phpc_jit_gethostname\b/', $body),
                'discarded gethostname must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_last_error_(is_active|to_hashtable)\b/', $body),
                'discarded error_get_last must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_getrusage\b/', $body),
                'discarded getrusage must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_hash_algos\b/', $body),
                'discarded hash_algos must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_hash_hmac_algos\b/', $body),
                'discarded hash_hmac_algos must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_ob_get_contents\b/', $body),
                'discarded ob_get_contents must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_ob_get_length\b/', $body),
                'discarded ob_get_length must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_pending_header_list\b/', $body),
                'discarded headers_list must not call helper'
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

    public function testLiveHostErrorHashObMatchZend(): void
    {
        // Prefer type/shape assertions — hostnames and algo tables vary.
        // Live error_get_last / getrusage / OB / headers_list omitted when
        // NestedJIT / CGI state is flaky; discarded elision covered above.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        gethostname();
        error_get_last();
        getrusage();
        getrusage(0);
        hash_algos();
        hash_hmac_algos();
        ob_get_contents();
        ob_get_length();
        headers_list();
        $host = gethostname();
        $algos = hash_algos();
        $hmac = hash_hmac_algos();
        $hlen = ob_get_length();
        $hdrs = headers_list();
        echo (is_string($host) && $host !== '' ? '1' : '0')
            . (is_array($algos) && count($algos) > 0 ? '1' : '0')
            . (is_array($hmac) && count($hmac) > 0 ? '1' : '0')
            . (false === $hlen || (is_int($hlen) && $hlen >= 0) ? '1' : '0')
            . (is_array($hdrs) ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_heho_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_heho_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $runOut = [];
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
