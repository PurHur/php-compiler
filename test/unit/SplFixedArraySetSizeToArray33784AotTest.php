<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFixedArray::setSize / toArray thin-AOT (#33784).
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArraySetSizeToArray33784AotTest extends TestCase
{
    public function testSetSizeToArrayMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33784_splfixedarray_setsize_toarray_aot.php');
    }

    public function testProxiesWired(): void
    {
        $root = dirname(__DIR__, 2);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'setSize'", $ctx);
        $this->assertStringContainsString("'toArray'", $ctx);
        $this->assertStringContainsString('#33784', $ctx);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFixedArrayJitHelper.php');
        $this->assertStringContainsString('compileSetSize', $helper);
        $this->assertStringContainsString('compileToArray', $helper);
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
        $bin = sys_get_temp_dir().'/sfx_33784_'.getmypid().'_'.md5($src);
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
