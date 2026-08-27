<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: exact long/long `/` → int; non-exact → float (#35337).
 *
 * @see php-src Zend/zend_operators.c div_function
 *
 * @group llvm
 * @group aot
 */
final class ExactIntDiv35337AotTest extends TestCase
{
    private const EXPECTED = <<<'OUT'
int(5)
float(3.5)
int(5)
int(5)
OUT;

    public function testVmExactIntDiv(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_35337_exact_int_div.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35337_exact_int_div.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED."\n", $out);
    }

    public function testAotExactIntDiv(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35337_exact_int_div.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35337_div_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED."\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
