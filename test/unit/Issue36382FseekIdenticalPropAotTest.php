<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `-1 === fseek($this->stream, $offset)` must not steal the compare UnaryMinus as
 * the seek offset (#36382 — Nyholm Stream::seek under thin AOT).
 *
 * php-src: ext/standard/file.c PHP_FUNCTION(fseek) / streams.c php_stream_seek.
 *
 * @group llvm
 */
final class Issue36382FseekIdenticalPropAotTest extends TestCase
{
    public function testMinusOneIdenticalFseekPropOffsetUsesParamNotCompareLiteral(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_fseek_identical_prop.php';
        $bin = sys_get_temp_dir().'/phpc_36382_fseek_id_'.bin2hex(random_bytes(4));
        $cmd = 'cd '.escapeshellarg($repo)
            .' && php bin/compile.php -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $clog, $crc);
        $this->assertSame(0, $crc, implode("\n", $clog));
        $this->assertFileExists($bin);
        try {
            $out = [];
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertSame("SEEKOK\n", implode("\n", $out)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testFseekNegSeekEndStillWorks(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_fseek_neg_seek_end.php';
        $bin = sys_get_temp_dir().'/phpc_36382_fseek_neg_'.bin2hex(random_bytes(4));
        $cmd = 'cd '.escapeshellarg($repo)
            .' && php bin/compile.php -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $clog, $crc);
        $this->assertSame(0, $crc, implode("\n", $clog));
        try {
            $out = [];
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertSame("2\n", implode("\n", $out)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
