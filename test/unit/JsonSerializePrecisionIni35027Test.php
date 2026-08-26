<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\IniGetLeafJitHelper;
use PHPCompiler\ext\standard\VmIni;

/**
 * AOT/VM json_encode + serialize honor ini_set(serialize_precision) (#35027).
 *
 * @see php-src ext/json/json_encoder.c php_json_encode_double
 * @see php-src ext/standard/var.c php_var_serialize_intern
 * @group llvm
 * @group aot
 */
final class JsonSerializePrecisionIni35027Test extends TestCase
{
    private const EXPECTED = "0.3333333333\n"
        ."d:0.3333333333;\n"
        ."0.10000000000000001\n"
        ."d:0.10000000000000001;\n"
        ."15\n"
        ."0.333333333333333\n"
        ."0.1\n"
        ."d:0.1;\n";

    protected function tearDown(): void
    {
        $runtime = new Runtime();
        VmIni::set($runtime->vmContext, 'serialize_precision', '-1');
        IniGetLeafJitHelper::iniRestore('serialize_precision');
        parent::tearDown();
    }

    public function testIniGetLeafFormatsSerializeAndJsonDouble(): void
    {
        IniGetLeafJitHelper::iniSet('serialize_precision', '10');
        $this->assertSame('0.3333333333', IniGetLeafJitHelper::formatSerializeDouble(1 / 3));
        $this->assertSame('0.3333333333', IniGetLeafJitHelper::formatJsonDouble(1 / 3));
        IniGetLeafJitHelper::iniSet('serialize_precision', '15');
        $this->assertSame('15', IniGetLeafJitHelper::iniGet('serialize_precision'));
        IniGetLeafJitHelper::iniRestore('serialize_precision');
    }

    public function testVmJsonSerializePrecisionIniMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_35027_json_serialize_precision_ini.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35027_json_serialize_precision_ini.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotJsonSerializePrecisionIniMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35027_json_serialize_precision_ini.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35027_'.getmypid().'.bin';
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
