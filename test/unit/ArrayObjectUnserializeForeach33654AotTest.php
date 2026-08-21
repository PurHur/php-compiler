<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach after unserialize(ArrayObject) must match Zend (#33654).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUnserializeForeach33654AotTest extends TestCase
{
    public function testForeachMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_unserialize_foreach_aot_33654.php');
    }

    public function testClassUserTypePropagateWired(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('propagateUnserializeSplHtBackedResultType', $src);
        $this->assertStringContainsString('#33654', $src);
        $this->assertStringContainsString('SplOuterIteratorHt::isHtBacked', $src);
        $fill = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/UnserializeSplArrayFillNestedJitHelper.php');
        $this->assertStringContainsString('phpc_native_ht_set_long_at', $fill);
        $this->assertStringContainsString('#33654', $fill);
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
        $bin = sys_get_temp_dir().'/ao_fe_33654_'.getmypid().'_'.md5($src);
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
