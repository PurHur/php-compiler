<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Nyholm-like __toString after php://memory write (rewind path).
 *
 * @group aot
 */
final class Issue36382NyholmStreamToStringAotTest extends TestCase
{
    public function testToStringAfterWriteMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/issue_36382_nyholm_stream_tostring_segv.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);

        $this->assertSame(0, $zend['code'], $zend['out'].$zend['err']);
        $this->assertSame(0, $aot['code'], $aot['out'].$aot['err']);
        $this->assertSame($zend['out'], $aot['out']);
        $this->assertSame("hello\n", $aot['out']);
    }

    /** @return array{code:int,out:string,err:string} */
    private function runPhp(string $src): array
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        $out = [];
        $code = 0;
        exec($cmd.' 2>/tmp/issue36382_tostring_zend.err', $out, $code);

        return [
            'code' => $code,
            'out' => implode("\n", $out).([] !== $out ? "\n" : ''),
            'err' => (string) @file_get_contents('/tmp/issue36382_tostring_zend.err'),
        ];
    }

    /** @return array{code:int,out:string,err:string} */
    private function runAot(string $src): array
    {
        $bin = '/tmp/issue36382_nyholm_stream_tostring';
        @unlink($bin);
        $compile = 'PHP_COMPILER_CACHE=0 '
            .escapeshellarg(PHP_BINARY)
            .' -d opcache.enable_cli=0 -d memory_limit=2048M '
            .escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' --no-cache -o '.escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cerr = [];
        $ccode = 0;
        exec($compile.' 2>/tmp/issue36382_tostring_compile.err', $cerr, $ccode);
        if (0 !== $ccode || !is_file($bin)) {
            return [
                'code' => $ccode ?: 1,
                'out' => implode("\n", $cerr),
                'err' => (string) @file_get_contents('/tmp/issue36382_tostring_compile.err'),
            ];
        }
        $out = [];
        $code = 0;
        exec(escapeshellarg($bin).' 2>/tmp/issue36382_tostring_aot.err', $out, $code);

        return [
            'code' => $code,
            'out' => implode("\n", $out).([] !== $out ? "\n" : ''),
            'err' => (string) @file_get_contents('/tmp/issue36382_tostring_aot.err'),
        ];
    }
}
