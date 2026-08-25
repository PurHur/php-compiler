<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #34639 — password_get_info NestedJIT helper must return array, not HashTable.
 *
 * php-src: ext/standard/password.c — php_password_get_info
 * Peer: PasswordJitHelper::algosArgv (#20652)
 */
final class Issue34639PasswordGetInfoEmptyAotTest extends TestCase
{
    public function testGetInfoHashtableReturnsArrayNotHashTable(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../ext/standard/PasswordJitHelper.php'
        );
        $this->assertMatchesRegularExpression(
            '/function getInfoHashtable\(string \$hash\): array/',
            $src
        );
        $this->assertStringContainsString('getInfoThin', $src);
        $this->assertStringContainsString('NestedJitCompileScope::isActive()', $src);
        $this->assertStringContainsString('#34639', $src);
        $this->assertStringNotContainsString(
            'infoToHashTable(VmPassword::getInfo($hash))',
            $src
        );
    }

    public function testAotMatchesZend(): void
    {
        $path = __DIR__.'/../repro/aot_password_get_info_empty.php';
        $zend = $this->runPhp((string) file_get_contents($path));
        $this->assertStringContainsString("2y\nbcrypt\n4\n", $zend);

        $bin = sys_get_temp_dir().'/phpc_34639_'.md5($path).'.bin';
        $proc = proc_open(
            ['php', 'bin/compile.php', '-o', $bin, $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), "compile failed: $err");
        $lines = [];
        exec(escapeshellarg($bin).' 2>&1', $lines, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, 'AOT exited '.$rc.': '.implode("\n", $lines));
        $this->assertSame($zend, implode("\n", $lines).(count($lines) ? "\n" : ''), 'AOT vs Zend');
    }

    private function runPhp(string $code): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'z34639');
        file_put_contents($tmp, $code);
        $lines = [];
        exec('php '.escapeshellarg($tmp).' 2>&1', $lines, $rc);
        @unlink($tmp);
        $this->assertSame(0, $rc, 'zend exited '.$rc);

        return implode("\n", $lines).(count($lines) ? "\n" : '');
    }
}
