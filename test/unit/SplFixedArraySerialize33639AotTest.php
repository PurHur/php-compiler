<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(SplFixedArray) emits null holes; NestedJIT export keeps TYPE_NULL (#33639).
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArraySerialize33639AotTest extends TestCase
{
    public function testSerializeMatchesZendIncludingNullHoles(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/splfixedarray_serialize_aot_33634.php');
    }

    public function testArrayLiteralNullSerializeMatchesZend(): void
    {
        $src = sys_get_temp_dir().'/ao_nullser_33639_'.getmypid().'.php';
        file_put_contents($src, "<?php echo serialize([1, null, 3]), PHP_EOL;\n");
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
        }
    }

    public function testUnsetHoleSerializeStillOmitsKey(): void
    {
        $src = sys_get_temp_dir().'/ao_unsetser_33639_'.getmypid().'.php';
        file_put_contents(
            $src,
            "<?php \$a=[1,2,3]; unset(\$a[1]); echo serialize(\$a), PHP_EOL;\n"
        );
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
        }
    }

    public function testExportSkipsUndefinedNotNull(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/Call/HashTableExportKeyValuePairs.php');
        $this->assertStringContainsString('TYPE_UNDEFINED', $src);
        $this->assertStringContainsString('#33639', $src);
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
        $bin = sys_get_temp_dir().'/ao_sfa_33639_'.getmypid().'_'.md5($src);
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
