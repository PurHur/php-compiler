<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: try { unset($obj->typed); $obj->typed .= } matches Zend (#34431).
 *
 * @group llvm
 * @group aot
 */
final class Issue34431UnsetTypedTryAotTest extends TestCase
{
    public function testInheritedUnsetConcatInsideTryMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34426_inherited_unset_concat.php';
        $bin = sys_get_temp_dir().'/phpc_34431_'.getmypid().'.bin';
        $expected = "Error:Typed property ParentS::\$p must not be accessed before initialization\nDONE\n";

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame($expected, implode("\n", $zendOut)."\n");

        try {
            $compileOut = [];
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($bin);
        }
    }
}
