<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\IniGetLeafJitHelper;
use PHPCompiler\ext\standard\VmFloatDtoa;
use PHPCompiler\ext\standard\VmIni;

/**
 * var_dump(float) honors ini_set(serialize_precision) — leftover of #32328 (#35020).
 *
 * @see php-src ext/standard/var.c php_var_dump IS_DOUBLE
 * @group llvm
 * @group aot
 */
final class VarDumpSerializePrecisionIni35020Test extends TestCase
{
    private const EXPECTED = "float(0.10000000000000001)\n"
        ."float(0.33333333333333331)\n"
        ."float(0.30000000000000004)\n"
        ."float(0.1)\n"
        ."float(0.33333333333333)\n"
        ."float(0.1)\n"
        ."float(0.3333333333333333)\n";

    protected function tearDown(): void
    {
        $runtime = new Runtime();
        VmIni::set($runtime->vmContext, 'serialize_precision', '-1');
        parent::tearDown();
    }

    public function testVmFloatDtoaHonorsSerializePrecisionIni(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmIni::set($ctx, 'serialize_precision', '17');
        $this->assertSame('0.10000000000000001', VmFloatDtoa::formatVarDump(0.1));
        VmIni::set($ctx, 'serialize_precision', '14');
        $this->assertSame('0.33333333333333', VmFloatDtoa::formatVarDump(1 / 3));
        VmIni::set($ctx, 'serialize_precision', '-1');
        $this->assertSame('0.1', VmFloatDtoa::formatVarDump(0.1));
    }

    public function testIniGetLeafParsesSeventeen(): void
    {
        $old = IniGetLeafJitHelper::iniSet('serialize_precision', '17');
        $this->assertSame('-1', $old);
        $this->assertSame('17', IniGetLeafJitHelper::iniGet('serialize_precision'));
        $this->assertSame(17, IniGetLeafJitHelper::getSerializePrecisionInt());
        IniGetLeafJitHelper::iniSet('serialize_precision', '20');
        $this->assertSame('20', IniGetLeafJitHelper::iniGet('serialize_precision'));
        IniGetLeafJitHelper::iniRestore('serialize_precision');
        $this->assertSame('-1', IniGetLeafJitHelper::iniGet('serialize_precision'));
    }

    public function testVmVarDumpSerializePrecisionIniMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_35020_var_dump_serialize_precision_ini.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35020_var_dump_serialize_precision_ini.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotVarDumpSerializePrecisionIniMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35020_var_dump_serialize_precision_ini.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35020_'.getmypid().'.bin';
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
