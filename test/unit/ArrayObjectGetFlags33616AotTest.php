<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ArrayObject/ArrayIterator getFlags/setFlags via __flags (#33616).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectGetFlags33616AotTest extends TestCase
{
    public function testGetFlagsSetFlagsMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_getflags_aot_33616.php');
    }

    public function testProxiesAndHelperWired(): void
    {
        $root = dirname(__DIR__, 2);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'getFlags'", $ctx);
        $this->assertStringContainsString('#33616', $ctx);
        $helper = (string) file_get_contents($root.'/lib/VM/ArrayObjectJitHelper.php');
        $this->assertStringContainsString('compileGetFlags', $helper);
        $this->assertStringContainsString('compileSetFlags', $helper);
        $ao = (string) file_get_contents($root.'/lib/JIT/Call/ArrayObjectMethod.php');
        $this->assertStringContainsString("'getflags'", $ao);
        $this->assertStringContainsString("'setflags'", $ao);
        $ai = (string) file_get_contents($root.'/lib/JIT/Call/ArrayIteratorMethod.php');
        $this->assertStringContainsString("'getflags'", $ai);
        $this->assertStringContainsString("'setflags'", $ai);
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
        $bin = sys_get_temp_dir().'/ao_getflags_33616_'.getmypid().'_'.md5($src);
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
