<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: ArrayObject/ArrayIterator `$o[]=` appends into `__spl_ht` (#34748 / re-#27286).
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectEmptyDimAppend34748AotTest extends TestCase
{
    public function testEmptyDimAppendMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34748_arrayobject_empty_dim_append.php');
    }

    public function testLegacy27286ReproMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/arrayobject_empty_dim_append_27286.php');
    }

    public function testConstructAndBackingSlotWired(): void
    {
        $root = dirname(__DIR__, 2);
        $construct = (string) file_get_contents($root.'/lib/JIT/Call/ArrayIteratorConstruct.php');
        $this->assertStringContainsString('#34748', $construct);
        $this->assertStringContainsString('initEmptyHashtableProperties', $construct);
        $object = (string) file_get_contents($root.'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('objectPropertySlot', $object);
        $this->assertStringContainsString('#34748', $object);
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
        $bin = sys_get_temp_dir().'/ao_34748_'.getmypid().'_'.md5($src);
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
