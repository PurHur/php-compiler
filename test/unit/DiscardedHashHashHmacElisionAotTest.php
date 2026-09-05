<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded hash / hash_hmac with compile-time known algo must not lower (#36386).
 * Discarded-only AOT must match Zend (live hash AOT is a separate SIGSEGV track).
 *
 * php-src: ext/hash/hash.c
 *
 * @group aot-lint
 */
final class DiscardedHashHashHmacElisionAotTest extends TestCase
{
    public function testDiscardedOnlyHashHashHmacHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, string $data, string $key): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                hash('sha256', $data);
                hash('md5', $data, false);
                hash_hmac('sha256', $data, $key);
                hash_hmac('sha1', $data, $key, true);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, 'payload', 'secret'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_hash_hmac_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_hash_hmac_only_'.getmypid().'.bin';
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
                preg_match_all('/call [^\n]*@(__compiler_hash|__compiler_hash_hmac)\b/', $body),
                'discarded hash/hash_hmac must be elided (no helper calls)'
            );

            $runOut = [];
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
}
