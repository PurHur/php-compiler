<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: {main} script-global strings must be truthy for JUMPIF / empty() (#32919).
 *
 * php-src: Zend/zend_operators.c zend_is_true / convert_to_boolean IS_STRING
 *
 * @group llvm
 * @group aot
 */
final class ScriptGlobalStringTruthy32919AotTest extends TestCase
{
    public function testVmScriptGlobalStringTernaryAndEmpty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$n = 'x';
echo ($n ? $n : 'z'), "\n";
echo ($n ? 'x' : 'z'), "\n";
echo empty($n) ? 'E' : 'N', "\n";
$z = '0';
echo ($z ? 'T' : 'F'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32919_sg_string.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("x\nx\nN\nF\n", $out);
    }

    public function testAotScriptGlobalStringTernaryAndEmpty(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32919_script_global_string_ternary.php';
        $bin = sys_get_temp_dir().'/phpc_sg_str_truthy_'.getmypid().'.bin';
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
            $this->assertSame("x\nx\nN\nF\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
