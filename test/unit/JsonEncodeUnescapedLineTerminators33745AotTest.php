<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * json_encode U+2028/U+2029: still \u-escaped under JSON_UNESCAPED_UNICODE (#33745).
 *
 * @see php-src ext/json/json_encoder.c php_json_escape_string
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeUnescapedLineTerminators33745AotTest extends TestCase
{
    private const EXPECTED = "\"\\u2028\"\n\"\\u2028\"\n\"\xE2\x80\xA8\"\n\"\\u2029\"\n\"\\u2029\"\n\"\xE2\x80\xA9\"\n\"\xE2\x80\xA8x\xE2\x80\xA9\"\n2048\n\"\xE2\x80\xA8\"\n";

    public function testVmJsonEncodeLineTerminators(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/json_encode_unescaped_line_terminators.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'json_encode_unescaped_line_terminators.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotJsonEncodeLineTerminators(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/json_encode_unescaped_line_terminators.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33745_'.getmypid().'.bin';
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
