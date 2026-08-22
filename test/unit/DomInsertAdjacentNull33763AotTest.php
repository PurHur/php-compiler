<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: insertAdjacentElement($where, null) — no SIGSEGV (#33763 / re-#32690).
 *
 * Default profile: method withheld (Zend 8.2) → undefined method Error at compile.
 * PROFILE=8.4: ?DOMElement null → NULL (php-src php_dom.c Z_PARAM_OBJ_OF_CLASS_OR_NULL).
 *
 * @group llvm
 * @group aot
 */
final class DomInsertAdjacentNull33763AotTest extends TestCase
{
    public function testDefaultProfileCompileRejectsUndefinedMethod(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33763_insert_adjacent_null_aot.php';
        $bin = sys_get_temp_dir().'/phpc_iae_null_33763_def_'.getmypid().'.bin';
        $compile = 'env -u PHP_COMPILER_PROFILE PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        @unlink($bin);
        $this->assertNotSame(0, $compileRc, 'default profile must not lower insertAdjacentElement');
        $joined = implode("\n", $compileOut);
        $this->assertStringContainsString('undefined method', strtolower($joined), $joined);
    }

    public function testProfile84AotReturnsNullForNullElement(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33763_insert_adjacent_null_aot.php';
        $bin = sys_get_temp_dir().'/phpc_iae_null_33763_84_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("NULL\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
