<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ArrayObject/ArrayIterator `$o[]=` after construct-with-array (#34748 / re-#27286).
 *
 * @see php-src ext/spl/spl_array.c spl_array_object_new_ex / spl_array_write_dimension
 *
 * @group llvm
 * @group aot
 */
final class ArrayObjectEmptyDimAppend34748AotTest extends TestCase
{
    private const EXPECT = "4|4\n4|4\n1|x\n4|5\n";

    public function testVmEmptyDimAppend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/arrayobject_empty_dim_append_27286.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'arrayobject_empty_dim_append_27286.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotEmptyDimAppend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/arrayobject_empty_dim_append_27286.php';
        $bin = sys_get_temp_dir().'/phpc_aot_ao_34748_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testConstructStoreAvoidsSpuriousAddref(): void
    {
        $root = dirname(__DIR__, 2);
        $construct = (string) file_get_contents($root.'/lib/JIT/Call/ArrayIteratorConstruct.php');
        $this->assertStringContainsString('#34748', $construct);
        $this->assertStringContainsString('initEmptyHashtableProperties', $construct);
        $this->assertStringNotContainsString(
            'propertyStore($slot, $copy, Variable::TYPE_HASHTABLE)',
            $construct
        );
        $object = (string) file_get_contents($root.'/lib/JIT/Builtin/Type/Object_.php');
        $start = strpos($object, 'function splBackingHashtable(Variable $obj): Variable');
        $this->assertNotFalse($start);
        $snippet = substr($object, (int) $start, 900);
        $this->assertStringContainsString('objectPropertySlot = $slot', $snippet);
        $this->assertStringContainsString('#34748', $snippet);
    }
}
