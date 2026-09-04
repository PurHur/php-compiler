<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded md5/crc32/base64_encode/soundex on typed strings must not lower (#36386).
 *
 * php-src:
 * - ext/standard/md5.c / sha1.c PHP_FUNCTION(md5|sha1)
 * - ext/standard/crc32.c PHP_FUNCTION(crc32)
 * - ext/standard/base64.c PHP_FUNCTION(base64_encode)
 * - ext/standard/string.c PHP_FUNCTION(soundex|metaphone|hebrev)
 * - ext/standard/uuencode.c PHP_FUNCTION(convert_uuencode)
 *
 * @group aot-lint
 */
final class DiscardedHashEncodeElisionAotTest extends TestCase
{
    public function testDiscardedHashEncodeAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                md5($s);
                sha1($s);
                crc32($s);
                base64_encode($s);
                soundex($s);
                metaphone($s);
                convert_uuencode($s);
                hebrev($s);
                $c += $i;
            }
            // Live uses stay — only discarded-only calls must vanish from IR.
            return $c + strlen($s) + strlen(md5($s)) + strlen(base64_encode($s));
        }
        echo work('Hello!', 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_hash_enc_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_hash_enc_'.getmypid().'.bin';
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

            // Discarded transforms must not allocate / call per iteration.
            // Live return md5($s) / base64_encode($s) may remain once outside the loop.
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@phpc_crc32_compute\b/', $body),
                'discarded crc32 must be elided'
            );
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@__compiler_soundex\b/', $body),
                'discarded soundex must be elided'
            );
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@__compiler_metaphone\b/', $body),
                'discarded metaphone must be elided'
            );
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@__compiler_convert_uuencode\b/', $body),
                'discarded convert_uuencode must be elided'
            );
            $this->assertSame(
                1,
                preg_match_all('/call [^\n]*@__compiler_base64_encode\b/', $body),
                'exactly one live base64_encode; discarded loop calls must be elided'
            );
            // md5 lowers via __compiler_hash — live return keeps one digest path.
            $this->assertLessThanOrEqual(
                2,
                preg_match_all('/call [^\n]*@__compiler_hash\b/', $body),
                'discarded md5/sha1 must not multiply hash calls beyond live uses'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // sum(0..4)=10 + strlen('Hello!')=6 + strlen(md5)=32 + strlen(base64_encode('Hello!'))=8 → 56
            $this->assertSame('56', $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
