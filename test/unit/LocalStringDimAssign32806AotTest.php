<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: local string dim assign must not clobber the string to HT (#32806).
 *
 * Regression from #32804's blunt `!$value->functionStaticGlobal` guard.
 * Array-default function-statics are retyped to TYPE_ARRAY in DECLARE instead.
 *
 * @see php-src Zend/zend_vm_def.h ZEND_ASSIGN_DIM
 *
 * @group llvm
 * @group aot
 */
final class LocalStringDimAssign32806AotTest extends TestCase
{
    private const EXPECTED = "aZc\n";

    public function testVmLocalStringDimAssign(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32806_local_string_dim_assign.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32806_local_string_dim_assign.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotLocalStringDimAssign(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32806_local_string_dim_assign.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32806_lsda_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
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
        } finally {
            @unlink($bin);
        }
    }
}
