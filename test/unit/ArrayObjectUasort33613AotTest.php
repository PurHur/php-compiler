<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ArrayObject/ArrayIterator uasort/uksort reorder __spl_ht (#33613).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUasort33613AotTest extends TestCase
{
    public function testUasortUksortMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_uasort_aot_33613.php');
    }

    public function testProxiesAndHelperWired(): void
    {
        $root = dirname(__DIR__, 2);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'uasort'", $ctx);
        $this->assertStringContainsString('#33613', $ctx);
        $helper = (string) file_get_contents($root.'/lib/VM/ArrayObjectJitHelper.php');
        $this->assertStringContainsString('compileUasort', $helper);
        $this->assertStringContainsString('compileUksort', $helper);
        $this->assertStringContainsString('UsortRuntime::uasortValues', $helper);
        $this->assertStringContainsString('UsortRuntime::uksortKeys', $helper);
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
        $bin = sys_get_temp_dir().'/ao_uasort_33613_'.getmypid().'_'.md5($src);
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
