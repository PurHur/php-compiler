<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: fopen + echo must fflush loaded stdout FILE*, not the global's address (#34737).
 *
 * @see php-src main/output.c php_output_flush
 *
 * @group llvm
 * @group aot
 */
final class FopenEchoFflushStdout34737AotTest extends TestCase
{
    private const EXPECT = "x\ny\nok\n";

    public function testVmFopenEchoFflushStdout(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34737_fopen_echo_fflush_stdout.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34737_fopen_echo_fflush_stdout.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotFopenEchoFflushStdout(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34737_fopen_echo_fflush_stdout.php';
        $bin = sys_get_temp_dir().'/phpc_aot_fopen_echo_34737_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $ok = 0;
            $last = '';
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $text = implode("\n", $runOut)."\n";
                $last = $text.' rc='.$runRc;
                if (0 === $runRc && self::EXPECT === $text) {
                    ++$ok;
                }
            }
            $this->assertSame(3, $ok, 'expected 3/3 clean runs; last='.$last);
        } finally {
            @unlink($bin);
        }
    }

    public function testEmitFflushStdoutLoadsFilePointer(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/ObStorageLlvm.php');
        $this->assertMatchesRegularExpression(
            '/function emitFflushStdout.*?builder->load\s*\(/s',
            $src,
            'emitFflushStdout must load FILE* from @stdout before fflush (#34737)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function emitFflushStdout.*?call\(\$fflush,\s*\$context->builder->pointerCast\(\$stdout,\s*\$i8p\)\)/s',
            $src,
            'must not pass address-of @stdout to fflush'
        );
    }
}
