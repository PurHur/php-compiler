<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: parse_ini_file(data://) honors allow_url_include (#34777 / peer #32104).
 *
 * @see php-src ext/standard/basic_functions.c PHP_FUNCTION(parse_ini_file)
 * @see php-src main/streams/streams.c allow_url_include
 *
 * @group llvm
 * @group aot
 */
final class ParseIniFileAllowUrlInclude34777AotTest extends TestCase
{
    public function testVmParseIniFileDataUriBlocked(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34777_parse_ini_allow_url_include.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34777_parse_ini_allow_url_include.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('data=false', $out);
        $this->assertStringContainsString('base64=false', $out);
        $this->assertStringContainsString("fgc='a=1'", $out);
    }

    public function testAotParseIniFileDataUriBlocked(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_aot_parse_ini_34777_'.getmypid().'.bin';
        // Literal data:// only — avoids pre-existing parseRuntimeFile NestedJIT verify (#34777).
        $src = '<?php var_dump(parse_ini_file("data://text/plain,a=1"));'
            .' var_dump(parse_ini_file("data://text/plain;base64,YT0x"));'
            .' var_dump(file_get_contents("data://text/plain,a=1"));';
        $srcFile = sys_get_temp_dir().'/phpc_aot_parse_ini_34777_'.getmypid().'.php';
        file_put_contents($srcFile, $src);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($srcFile).' 2>&1';
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $joined = implode("\n", $runOut)."\n";
            // Two blocked parse_ini_file calls then fgc success — must not bake INI arrays (#34777).
            $this->assertSame(
                "bool(false)\nbool(false)\nstring(3) \"a=1\"\n",
                $joined
            );
        } finally {
            @unlink($bin);
            @unlink($srcFile);
        }
    }

    public function testAotParseIniFileFilesystemStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $ini = sys_get_temp_dir().'/phpc_aot_parse_ini_fs_34777_'.getmypid().'.ini';
        file_put_contents($ini, "b=2\n");
        $bin = sys_get_temp_dir().'/phpc_aot_parse_ini_fs_34777_'.getmypid().'.bin';
        $srcFile = sys_get_temp_dir().'/phpc_aot_parse_ini_fs_34777_'.getmypid().'.php';
        file_put_contents($srcFile, '<?php var_dump(parse_ini_file('.var_export($ini, true).'));');
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($srcFile).' 2>&1';
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("array(1) {\n  [\"b\"]=>\n  string(1) \"2\"\n}\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
            @unlink($srcFile);
            @unlink($ini);
        }
    }

    public function testParseIniFileCallUsesAllowUrlIncludeGate(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/parse_ini_file.php');
        $this->assertStringContainsString(
            'rejectCompileTimeBlockedScriptOpen',
            $src,
            'compile-time data:// must not bake VmFsReadNative success (#34777)'
        );
        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmParseIni.php');
        $this->assertStringContainsString(
            'blockedForScriptOpen',
            $vm,
            'VM parse_ini_file must honor allow_url_include (#34777)'
        );
        $this->assertStringContainsString(
            'readPathContentsViaOpen',
            $vm,
            'VM must open wrapper URIs when allow_url_include permits (#34777)'
        );
    }
}
