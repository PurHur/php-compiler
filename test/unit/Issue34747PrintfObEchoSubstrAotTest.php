<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: printf links __phpc_ob_echo_substr via ObOutputRuntime (#34747).
 *
 * @see php-src ext/standard/formatted_print.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34747PrintfObEchoSubstrAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
d=7
s=hi
f=1.5
TXT;

    public function testVmPrintf(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34747_printf_ob_echo_substr_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34747_printf_ob_echo_substr_aot.php'));
        $this->assertSame(self::EXPECTED."\n", (string) ob_get_clean());
    }

    public function testAotPrintfLinksAndMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34747_printf_ob_echo_substr_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34747_printf_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED."\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($bin);
        }
    }
}
