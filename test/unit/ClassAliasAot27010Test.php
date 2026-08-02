<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for class_alias() (#27010).
 *
 * Standalone AOT must register aliases via the compile-unit Object_ registry
 * (not the host vmContext), so class_exists() literal folding sees the alias.
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 *
 * @group llvm
 * @group aot
 */
final class ClassAliasAot27010Test extends TestCase
{
    public function testAotClassAliasRegistersAlias(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27010_aot_class_alias.php';
        $bin = sys_get_temp_dir().'/phpc_aot_class_alias_27010_'.getmypid().'.bin';
        @unlink($bin);

        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(['true', 'true'], $runOut);
            }
        } finally {
            @unlink($bin);
        }
    }
}
