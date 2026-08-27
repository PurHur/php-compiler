<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: file-scope const of enum case keeps declared class casing (#35332, leftover #34783).
 *
 * php-src: Zend/zend_enum.c, ext/standard/var.c
 *
 * @group llvm
 * @group aot
 */
final class Issue35332EnumConstDisplayCaseAotTest extends TestCase
{
    public function testFileScopeEnumConstDisplayMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_35332_enum_const_display_case.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('enum(E::A)', $aot);
        $this->assertStringContainsString('string(1) "E"', $aot);
        $this->assertStringNotContainsString('enum(e::A)', $aot);
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/enum_const_display_35332_'.getmypid();
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $compileOut, $crc);
        $this->assertSame(0, $crc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $arc);
        @unlink($bin);
        $this->assertSame(0, $arc, implode("\n", $runOut));

        return implode("\n", $runOut)."\n";
    }
}
