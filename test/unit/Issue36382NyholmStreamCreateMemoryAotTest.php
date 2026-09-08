<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Nyholm-shaped Stream::create($string) via php://memory under AOT
 * (replaces AotStringStream36382 fixture patch after #37259).
 *
 * php-src: ext/standard/streams.c php_stream_memory_ops.
 *
 * @group aot
 */
final class Issue36382NyholmStreamCreateMemoryAotTest extends TestCase
{
    public function testCreateStringWriteMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/issue_36382_nyholm_stream_create_memory.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);

        $this->assertSame(0, $zend['code'], $zend['out'].$zend['err']);
        $this->assertSame(0, $aot['code'], $aot['out'].$aot['err']);
        $this->assertSame($zend['out'], $aot['out']);
        $this->assertSame("hello\n", $aot['out']);
    }

    public function testSetupNoLongerAppliesAotStringStreamPatch(): void
    {
        $setup = (string) file_get_contents(dirname(__DIR__, 2).'/script/composer/setup-slim-hello-36382.sh');
        $this->assertStringNotContainsString('patch-nyholm-stream-string-body-36382.php', $setup);
        $this->assertStringContainsString('patch-nyholm-stream-36382.php', $setup);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/script/composer/patch-nyholm-stream-string-body-36382.php');
    }

    /** @return array{code:int,out:string,err:string} */
    private function runPhp(string $src): array
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        $out = [];
        $code = 0;
        exec($cmd.' 2>/tmp/issue36382_stream_create_zend.err', $out, $code);

        return [
            'code' => $code,
            'out' => implode("\n", $out).([] !== $out ? "\n" : ''),
            'err' => (string) @file_get_contents('/tmp/issue36382_stream_create_zend.err'),
        ];
    }

    /** @return array{code:int,out:string,err:string} */
    private function runAot(string $src): array
    {
        $bin = '/tmp/issue36382_nyholm_stream_create_memory';
        @unlink($bin);
        $compile = 'PHP_COMPILER_CACHE=0 '
            .escapeshellarg(PHP_BINARY)
            .' -d opcache.enable_cli=0 -d memory_limit=2048M '
            .escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cerr = [];
        $ccode = 0;
        exec($compile.' 2>/tmp/issue36382_stream_create_compile.err', $cerr, $ccode);
        if (0 !== $ccode || !is_file($bin)) {
            return [
                'code' => $ccode ?: 1,
                'out' => implode("\n", $cerr),
                'err' => (string) @file_get_contents('/tmp/issue36382_stream_create_compile.err'),
            ];
        }
        $out = [];
        $code = 0;
        exec(escapeshellarg($bin).' 2>/tmp/issue36382_stream_create_aot.err', $out, $code);

        return [
            'code' => $code,
            'out' => implode("\n", $out).([] !== $out ? "\n" : ''),
            'err' => (string) @file_get_contents('/tmp/issue36382_stream_create_aot.err'),
        ];
    }
}
