<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) (#35339 leftover of #11050/#3281).
 *
 * @see php-src ext/json/json_encoder.c PHP_JSON_PRETTY_PRINT
 *
 * @group aot-lint
 */
final class JsonEncodeFlagsOrAotTest extends TestCase
{
    public function testVmFlagsOrPrettyUnescaped(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_35339_json_encode_flags_or_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35339_json_encode_flags_or_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('"p": "a/b"', $out);
        $this->assertStringContainsString("\n", $out);
        $this->assertStringNotContainsString('a\/b', $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotFlagsOrMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35339_json_encode_flags_or_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35339_json_encode_flags_or_aot.php'));
        $vmOut = (string) ob_get_clean();

        $bin = sys_get_temp_dir().'/phpc_json_flags_or_'.getmypid().'.bin';
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
}
