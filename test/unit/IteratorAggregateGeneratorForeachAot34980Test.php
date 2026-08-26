<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach over IteratorAggregate whose getIterator() yields (#34980).
 *
 * @group llvm
 * @group aot
 */
final class IteratorAggregateGeneratorForeachAot34980Test extends TestCase
{
    public function testForeachMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/iterator_aggregate_generator_foreach_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_ia_gen_foreach_34980_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $crc);
        $this->assertSame(0, $crc, "compile failed:\n".implode("\n", $out));
        $this->assertFileExists($bin);

        ob_start();
        $zendRc = 0;
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendRc);
        $zend = ob_get_clean();

        ob_start();
        $aotRc = 0;
        passthru(escapeshellarg($bin).' 2>&1', $aotRc);
        $aot = ob_get_clean();
        @unlink($bin);

        $this->assertSame(0, $zendRc);
        $this->assertSame(0, $aotRc, "AOT rc=$aotRc out=$aot");
        $this->assertSame($zend, $aot);
    }
}
