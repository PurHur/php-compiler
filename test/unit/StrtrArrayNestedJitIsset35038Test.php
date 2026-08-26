<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: strtr(array) NestedJIT must translate (strlen walk — not isset) (#35038).
 *
 * @see php-src ext/standard/string.c PHP_FUNCTION(strtr)
 *
 * @group llvm
 * @group aot
 */
final class StrtrArrayNestedJitIsset35038Test extends TestCase
{
    private const EXPECTED = "HEllo\nxy\nXYZ\nnoop\nempty\n";

    public function testHelperSourceUsesStrlenNotIssetWalk(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/StrtrArrayJitHelper.php');
        $this->assertStringContainsString('\\strlen($subject)', $src);
        $this->assertStringContainsString('\\strlen($from)', $src);
        $this->assertStringNotContainsString('while (isset($subject[$i]))', $src);
        $this->assertStringNotContainsString('while (isset($from[$flen]))', $src);
        $this->assertStringNotContainsString('!isset($subject[0])', $src);
    }

    public function testVmStrtrArrayMatchesExpected(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_strtr_array_isset_35038.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_strtr_array_isset_35038.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotStrtrArrayMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_strtr_array_isset_35038.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35038_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
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
