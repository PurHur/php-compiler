<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: unset() on typed property with default — read throws Error (#33007, #33886, re-#4863).
 *
 * @see php-src Zend/zend_object_handlers.c zend_std_unset_property
 *
 * @group llvm
 * @group aot
 */
final class UnsetTypedPropDefault33007AotTest extends TestCase
{
    private const EXPECT = "bool(false)\nerr\nbool(false)\nError\n";

    public function testVmUnsetTypedPropMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33007_unset_typed_prop_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33007_unset_typed_prop_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotUnsetTypedPropMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33007_unset_typed_prop_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33886_'.getmypid().'.bin';
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
}
