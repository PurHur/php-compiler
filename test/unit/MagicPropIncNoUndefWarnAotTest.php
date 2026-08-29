<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: $obj->n++ with __get/__set persists via __set (#31992 leftover).
 *
 * @see php-src Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 *
 * @group llvm
 * @group aot
 */
final class MagicPropIncNoUndefWarnAotTest extends TestCase
{
    private const EXPECTED = "inc=2\n";

    public function testVmMagicPropPostInc(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_magic_prop_inc.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_magic_prop_inc.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMagicPropPostInc(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_magic_prop_inc.php';
        $bin = sys_get_temp_dir().'/phpc_magic_prop_inc_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
            $this->assertStringNotContainsString('Deprecated', implode("\n", $runOut));
            $this->assertStringNotContainsString('Undefined property', implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
