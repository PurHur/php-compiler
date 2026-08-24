<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_dump() arrays must not SIGABRT (#34498).
 *
 * Thin StringVarDump scalar bridge aborted non-scalars (#23540); fix: VarDumpArrayLlvm
 * (peer SerializeArrayLlvm #34483 / JsonEncodeArrayLlvm) + leveled __compiler_var_dump_ex.
 *
 * @see php-src ext/standard/var.c php_var_dump / php_array_element_dump
 *
 * @group llvm
 * @group aot
 */
final class VarDumpArray34498AotTest extends TestCase
{
    private const EXPECTED = "array(0) {\n"
        ."}\n"
        ."array(2) {\n"
        ."  [0]=>\n"
        ."  int(1)\n"
        ."  [1]=>\n"
        ."  int(2)\n"
        ."}\n"
        ."array(3) {\n"
        ."  [\"ok\"]=>\n"
        ."  bool(true)\n"
        ."  [\"n\"]=>\n"
        ."  int(1)\n"
        ."  [\"msg\"]=>\n"
        ."  string(2) \"hi\"\n"
        ."}\n"
        ."array(1) {\n"
        ."  [0]=>\n"
        ."  array(2) {\n"
        ."    [0]=>\n"
        ."    int(1)\n"
        ."    [1]=>\n"
        ."    int(2)\n"
        ."  }\n"
        ."}\n";

    public function testVmVarDumpArraysMatchZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34498_vardump_array_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34498_vardump_array_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotVarDumpArraysNoAbort(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34498_vardump_array_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34498_vd_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
