<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `$obj instanceof $className` must compile and match Zend (#32766).
 *
 * @see php-src Zend/zend_execute.c ZEND_INSTANCEOF
 *
 * @group aot-lint
 */
final class InstanceOfVarClassAotTest extends TestCase
{
    private const EXPECTED = "bool(true)\nbool(true)\n";

    public function testVmInstanceofVarClassName(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32766_instanceof_var_class_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32766_instanceof_var_class_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotInstanceofVarClassNameMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32766_instanceof_var_class_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32766_instanceof_var_class_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_instanceof_var_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testInstanceOfHelperPassesStringPayloadsToStrcasecmp(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/InstanceOfHelper.php');
        $this->assertStringContainsString('allDeclaredClassLowerNames', $src);
        $this->assertStringContainsString('ABI_STRNCASECMP', $src);
        $this->assertStringContainsString('restoreInsertBlock', $src);
        // ensureBridge already restores insert; clearing it made valueBoxRhsKind parentless (#32766).
        $this->assertStringNotContainsString('clearInsertionPosition', $src);
    }
}
