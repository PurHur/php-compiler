<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: fgets/fclose/fwrite/fputs/stream_get_contents excess argc → ArgumentCountError (#30721).
 *
 * php-src: ext/standard/file.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30721StreamFileExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'fgets' => [
                'code' => "<?php\nfgets(STDIN, 10, 3);\n",
                'needle' => 'fgets() expects at most 2 arguments, 3 given',
            ],
            'fclose' => [
                'code' => "<?php\nfclose(STDIN, 2);\n",
                'needle' => 'fclose() expects exactly 1 argument, 2 given',
            ],
            'fwrite' => [
                'code' => "<?php\nfwrite(STDOUT, 'x', 1, 4);\n",
                'needle' => 'fwrite() expects at most 3 arguments, 4 given',
            ],
            'fputs' => [
                'code' => "<?php\nfputs(STDOUT, 'x', 1, 4);\n",
                'needle' => 'fputs() expects at most 3 arguments, 4 given',
            ],
            'stream_get_contents' => [
                'code' => "<?php\nstream_get_contents(STDIN, 1, -1, 4);\n",
                'needle' => 'stream_get_contents() expects at most 3 arguments, 4 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30721_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30721_ex_'.$label.'_'.getmypid().'.bin';
            file_put_contents($src, $case['code']);
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, $label.' compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
            try {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertNotSame(0, $runRc, $label.' should abort');
                $joined = implode("\n", $runOut);
                $this->assertStringContainsString($case['needle'], $joined, $label);
                $this->assertStringContainsString('ArgumentCountError', $joined, $label);
                $this->assertStringNotContainsString('LogicException', $joined, $label);
                $this->assertStringNotContainsString('in this compiler build', $joined, $label);
            } finally {
                @unlink($src);
                @unlink($bin);
            }
        }
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30721_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30721_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    fgets(STDIN, 10, 3);
    echo "fgets NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'fgets ', $e->getMessage(), "\n";
}
try {
    fclose(STDIN, 2);
    echo "fclose NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'fclose ', $e->getMessage(), "\n";
}
try {
    fwrite(STDOUT, 'x', 1, 4);
    echo "fwrite NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'fwrite ', $e->getMessage(), "\n";
}
try {
    fputs(STDOUT, 'x', 1, 4);
    echo "fputs NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'fputs ', $e->getMessage(), "\n";
}
try {
    stream_get_contents(STDIN, 1, -1, 4);
    echo "stream_get_contents NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'stream_get_contents ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "fgets fgets() expects at most 2 arguments, 3 given\n"
            ."fclose fclose() expects exactly 1 argument, 2 given\n"
            ."fwrite fwrite() expects at most 3 arguments, 4 given\n"
            ."fputs fputs() expects at most 3 arguments, 4 given\n"
            ."stream_get_contents stream_get_contents() expects at most 3 arguments, 4 given\n"
        );
    }

    private function compileAndAssertOutput(string $root, string $src, string $bin, string $expected): void
    {
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
