<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: fopen + echo must not abort on exit — emitFflushStdout loads FILE* (#34737).
 *
 * @see php-src main/output.c php_output_flush
 * @see php-src main/main.c request shutdown flush
 *
 * @group llvm
 * @group aot
 */
final class Issue34737FopenEchoFflushAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
memory=ok
temp=ok
file=ok
TXT;

    public function testVmFopenEchoExitsCleanly(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34737_fopen_echo_fflush_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34737_fopen_echo_fflush_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED."\n", $out);
    }

    public function testAotFopenEchoExitsCleanly(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34737_fopen_echo_fflush_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34737_fflush_'.getmypid().'.bin';
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
