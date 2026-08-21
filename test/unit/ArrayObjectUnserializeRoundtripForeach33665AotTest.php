<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach after unserialize(serialize(ArrayObject/ArrayIterator)) (#33665).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectUnserializeRoundtripForeach33665AotTest extends TestCase
{
    public function testRoundtripForeachMatchesZend(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/arrayobject_unserialize_roundtrip_foreach_aot_33665.php'
        );
    }

    public function testSerializeHintPropagateWired(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('propagateSerializeSplHtBackedPayloadType', $src);
        $this->assertStringContainsString('serializedSplClassUserType', $src);
        $this->assertStringContainsString('#33665', $src);
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
        $bin = sys_get_temp_dir().'/ao_rt_33665_'.getmypid().'_'.md5($src).'_'.bin2hex(random_bytes(4));
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
