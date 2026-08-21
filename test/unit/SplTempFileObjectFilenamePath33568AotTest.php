<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SplTempFileObject getFilename/getPath empty-path + next/valid (#33568).
 *
 * @group llvm
 * @group aot
 */
final class SplTempFileObjectFilenamePath33568AotTest extends TestCase
{
    public function testVmAndAotMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33568_spltemp_filename_path.php');
    }

    public function testHelperStoresEmptyPathOnTempConstruct(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('storeSplFileInfoPathParts', $helper);
        $this->assertStringContainsString('#33568', $helper);
        $this->assertStringContainsString('FLAG_READ_AHEAD', $helper);
        $vm = (string) file_get_contents($root.'/ext/spl/SplTempFileObjectBuiltin.php');
        $this->assertStringContainsString('setPath($object, \'\')', $vm);
        $next = (string) file_get_contents($root.'/ext/spl/SplFileObjectStorage.php');
        $this->assertStringContainsString('Eager-reading when current is null', $next);
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
        $bin = sys_get_temp_dir().'/spl_fn_33568_'.getmypid().'_'.md5($src);
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
