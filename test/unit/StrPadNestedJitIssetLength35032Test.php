<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: str_pad must pad correctly (native phpc_str_pad_r1; was NestedJIT isset-length) (#35032 / #36388).
 *
 * @see php-src ext/standard/string.c PHP_FUNCTION(str_pad)
 * @see test/differential/cases/c11_strcmp.php
 * @see test/differential/cases/e15_str_fns.php
 *
 * @group llvm
 * @group aot
 */
final class StrPadNestedJitIssetLength35032Test extends TestCase
{
    private const EXPECTED = "pxxxx\n----p\n--p--\np----\nhello world.....\n";

    public function testHelperSourceUsesStrlenNotIssetLengthWalk(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/StrPadJitHelper.php');
        $this->assertStringContainsString('\\strlen($input)', $src);
        $this->assertStringContainsString('\\substr($padString, 0, $remainder)', $src);
        $this->assertStringNotContainsString('while (isset($string[$len]))', $src);
        $this->assertStringNotContainsString('$padString[$i]', $src);
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StrPadRuntime.php');
        $this->assertStringContainsString('phpc_str_pad_r1', $runtime);
    }

    public function testVmStrPadMatchesExpected(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_str_pad_isset_length_35032.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_str_pad_isset_length_35032.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotStrPadMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_str_pad_isset_length_35032.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35032_'.getmypid().'.bin';
        // Native phpc_str_pad_r1 — default helper-runtime link (O=0 NestedJIT path retired for str_pad; #36388).
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
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
