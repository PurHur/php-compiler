<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_detect_order(['UTF-8','ASCII']) compile-time array setter (#35278).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_detect_order)
 *
 * @group llvm
 * @group aot
 */
final class MbDetectOrderArraySetterAotTest extends TestCase
{
    public function testAotArraySetterMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_detect_order_array.php');
    }

    public function testCallAcceptsCompileTimeArray(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/mb_detect_order.php');
        $this->assertStringContainsString('compileTimeOrderFromNativeArray', $src);
        $this->assertStringContainsString('compileTimeArray', $src);
        $this->assertStringContainsString(
            'JIT setter requires a compile-time string or array',
            $src
        );
        $this->assertStringNotContainsString(
            'JIT setter requires a compile-time string in this compiler build',
            $src
        );
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_detect_order_array_35278_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
