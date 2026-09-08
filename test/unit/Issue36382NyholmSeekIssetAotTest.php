<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nyholm Stream::seek isset + (-1 === fseek) after php://memory write under AOT (#36382).
 *
 * php-src: ext/standard/file.c PHP_FUNCTION(fseek) / streams.c php_stream_seek.
 *
 * @group llvm
 */
final class Issue36382NyholmSeekIssetAotTest extends TestCase
{
    public function testNyholmSeekIssetAfterWrite(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_nyholm_seek_isset.php';
        $bin = sys_get_temp_dir().'/phpc_36382_seek_isset_'.bin2hex(random_bytes(4));
        $cmd = 'cd '.escapeshellarg($repo)
            .' && php bin/compile.php -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $clog, $crc);
        $this->assertSame(0, $crc, implode("\n", $clog));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $out = [];
                exec(escapeshellarg($bin).' 2>&1', $out, $rc);
                $this->assertSame(0, $rc, "run {$i}: ".implode("\n", $out));
                $this->assertSame("hello\n", implode("\n", $out)."\n", "run {$i}");
            }
        } finally {
            @unlink($bin);
        }
    }
}
