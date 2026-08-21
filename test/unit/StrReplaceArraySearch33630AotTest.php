<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: str_replace array $search must match Zend (no SIGSEGV) (#33630).
 *
 * @group llvm
 * @group aot
 */
final class StrReplaceArraySearch33630AotTest extends TestCase
{
    public function testArraySearchReplaceMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33630_str_replace_array_search.php');
    }

    public function testKnownArrayDetectsCompileTimeArray(): void
    {
        $root = dirname(__DIR__, 2);
        $subject = (string) file_get_contents($root.'/ext/standard/JitStrReplaceSubject.php');
        $this->assertStringContainsString('compileTimeArray', $subject);
        $this->assertStringContainsString('#33630', $subject);
        $replace = (string) file_get_contents($root.'/ext/standard/str_replace.php');
        $this->assertStringContainsString('JitStrReplaceSubject::isKnownArray', $replace);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $this->assertSame("xb\nxy\nXXXX\nxb\nxb\n", $zend."\n");
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
        $bin = sys_get_temp_dir().'/strrep_33630_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
