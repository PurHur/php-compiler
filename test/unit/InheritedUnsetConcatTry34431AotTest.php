<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: try { unset($obj->typed); $obj->typed .= 'x' } matches Zend Error (#34431).
 *
 * inheritUndefinedLocals nulls CFG Operand::$type; UnsetHelperLlvm must recover
 * classUserType so typed unset marks UNDEF (peer #34382 / #32749).
 *
 * @see php-src Zend/zend_object_handlers.c zend_std_unset_property
 * @see php-src Zend/zend_vm_def.h ZEND_ASSIGN_OBJ_OP
 *
 * @group llvm
 * @group aot
 */
final class InheritedUnsetConcatTry34431AotTest extends TestCase
{
    private const EXPECT = "Typed property ParentS::\$p must not be accessed before initialization\nDONE\n";

    public function testVmInheritedUnsetConcatTryMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34431_inherited_unset_concat.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34431_inherited_unset_concat.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotInheritedUnsetConcatTryMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34431_inherited_unset_concat.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34431_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $compileText = implode("\n", $compileOut);
        $this->assertStringNotContainsString(
            'UnsetHelperLlvm.php',
            $compileText,
            'compile must not warn from UnsetHelperLlvm (#34431)'
        );
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
