<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT typed `: array` return of a user array property must survive
 * foreach (MessageTrait::getHeaders / Slim CGI headers).
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN ZVAL_COPY of IS_ARRAY.
 *
 * @group aot
 */
final class Issue36382GetHeadersTypedArrayReturnAotTest extends TestCase
{
    public function testTypedArrayPropertyReturnForeachMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/issue_36382_getheaders_typed_array_return.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);

        $this->assertSame(0, $zend['code'], $zend['out'].$zend['err']);
        $this->assertSame(0, $aot['code'], $aot['out'].$aot['err']);
        $this->assertSame($zend['out'], $aot['out']);
        $this->assertStringContainsString('Content-Type:text/plain', $aot['out']);
        $this->assertStringContainsString('hello', $aot['out']);
    }

    /** @return array{code:int,out:string,err:string} */
    private function runPhp(string $src): array
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        $out = [];
        $code = 0;
        exec($cmd.' 2>/tmp/issue36382_getheaders_typed_zend.err', $out, $code);

        return [
            'code' => $code,
            'out' => implode("\n", $out).([] !== $out ? "\n" : ''),
            'err' => (string) @file_get_contents('/tmp/issue36382_getheaders_typed_zend.err'),
        ];
    }

    /** @return array{code:int,out:string,err:string} */
    private function runAot(string $src): array
    {
        $bin = '/tmp/issue36382_getheaders_typed_array_return';
        @unlink($bin);
        $compile = 'PHP_COMPILER_CACHE=0 '
            .escapeshellarg(PHP_BINARY)
            .' -d opcache.enable_cli=0 -d memory_limit=2048M '
            .escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cerr = [];
        $ccode = 0;
        exec($compile.' 2>/tmp/issue36382_getheaders_typed_compile.err', $cerr, $ccode);
        if (0 !== $ccode || !is_file($bin)) {
            return [
                'code' => $ccode ?: 1,
                'out' => implode("\n", $cerr),
                'err' => (string) @file_get_contents('/tmp/issue36382_getheaders_typed_compile.err'),
            ];
        }
        $out = [];
        $code = 0;
        exec(escapeshellarg($bin).' 2>/tmp/issue36382_getheaders_typed_aot.err', $out, $code);

        return [
            'code' => $code,
            'out' => implode("\n", $out).([] !== $out ? "\n" : ''),
            'err' => (string) @file_get_contents('/tmp/issue36382_getheaders_typed_aot.err'),
        ];
    }
}
