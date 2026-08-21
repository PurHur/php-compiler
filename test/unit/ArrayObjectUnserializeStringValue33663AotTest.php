<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize(ArrayObject) restores string values in bag (#33663).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUnserializeStringValue33663AotTest extends TestCase
{
    public function testUnserializeStringValueMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_unserialize_string_value_33663.php');
    }

    public function testHelperAcceptsStringValues(): void
    {
        $root = dirname(__DIR__, 2);
        $bagFill = (string) file_get_contents($root.'/ext/standard/UnserializeSplArrayFillNestedJitHelper.php');
        $this->assertStringContainsString('phpc_native_ht_set_string_key(', $bagFill);
        $this->assertStringContainsString('#33663', $bagFill);
        $helper = (string) file_get_contents($root.'/lib/VM/ArrayObjectJitHelper.php');
        $this->assertStringContainsString('phpc_native_ht_set_string_key', $helper);
    }

    public function testPriorIntValueReproStillMatches(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_unserialize_aot_33636.php');
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
        $bin = sys_get_temp_dir().'/ao_unser_33663_'.getmypid().'_'.md5($src);
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
