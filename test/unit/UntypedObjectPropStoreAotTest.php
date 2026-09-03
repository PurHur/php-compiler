<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * AOT: untyped / array-elem property stores use runtime class_id (#36386).
 *
 * @see php-src Zend/zend_object_handlers.c zend_get_property_offset
 *
 * @group llvm
 * @group aot
 */
final class UntypedObjectPropStoreAotTest extends TestCase
{
    private const EXPECTED = "u=2\na=2:2\nt=9\n";

    public function testAotUntypedAndArrayElemPropStoreMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/i36386_array_elem_prop_store.php';
        $bin = sys_get_temp_dir().'/phpc_i36386_prop_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_CACHE=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $zendOut = [];
            $zendRc = 0;
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $this->assertSame(self::EXPECTED, implode("\n", $zendOut)."\n");

            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
