<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode(DateTime*) must emit Zend date/timezone wire, not {} (#33752 / re-#14143).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeDateTime33752AotTest extends TestCase
{
    public function testAotMatchesZendUtcFixture(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33752_json_encode_datetime_aot.php');
    }

    public function testFoldHelperPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/JitJsonEncode.php');
        $this->assertStringContainsString('tryFoldDateTimeFamily', $src);
        $this->assertStringContainsString('#33752', $src);
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
        $bin = sys_get_temp_dir().'/je_33752_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
