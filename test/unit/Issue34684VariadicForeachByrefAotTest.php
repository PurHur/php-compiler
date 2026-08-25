<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach over &...$args must compile and match Zend (#34684).
 *
 * ARG_RECV must not box the packed HT into a value box — that path emitted
 * parentless __value__readObject IR (Module.php:180) and broke write-back.
 */
final class Issue34684VariadicForeachByrefAotTest extends TestCase
{
    public function testForeachByRefVariadicMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_variadic_byref.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, 'AOT must match Zend for foreach-by-ref over &...$args (#34684)');
    }

    public function testForeachValueVariadicMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_variadic_foreach_value.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, 'AOT must match Zend for foreach over ...$args (#34684)');
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $work = sys_get_temp_dir().'/phpc_34684_'.bin2hex(random_bytes(4));
        mkdir($work);
        $bin = $work.'/out';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
        $this->assertSame(0, $runCode, implode("\n", $runOut));
        @unlink($bin);
        @rmdir($work);

        return implode("\n", $runOut)."\n";
    }
}
