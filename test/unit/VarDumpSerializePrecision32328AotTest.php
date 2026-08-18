<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_dump(float) uses PG(serialize_precision) / %.*H (#32328).
 *
 * @see php-src ext/standard/var.c php_var_dump IS_DOUBLE
 * @see php-src Zend/zend_strtod.c zend_gcvt
 *
 * @group llvm
 * @group aot
 */
final class VarDumpSerializePrecision32328AotTest extends TestCase
{
    private const EXPECTED = "0.33333333333333\n"
        ."float(0.1)\n"
        ."float(0.3333333333333333)\n"
        ."float(0.30000000000000004)\n"
        ."float(9.223372036854776E+18)\n"
        ."float(INF)\n"
        ."float(NAN)\n";

    public function testVmVarDumpSerializePrecisionMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32328_var_dump_serialize_precision.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32328_var_dump_serialize_precision.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotVarDumpSerializePrecisionMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32328_var_dump_serialize_precision.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32328_'.getmypid().'.bin';
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
        } finally {
            @unlink($bin);
        }
    }
}
