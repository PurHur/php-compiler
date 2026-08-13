<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: session excess argc → ArgumentCountError (#30684).
 *
 * php-src: ext/session/session.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30684SessionExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'session_id' => [
                'code' => "<?php\nsession_id(null, 1);\n",
                'needle' => 'session_id() expects at most 1 argument, 2 given',
            ],
            'session_name' => [
                'code' => "<?php\nsession_name(null, 1);\n",
                'needle' => 'session_name() expects at most 1 argument, 2 given',
            ],
            'session_module_name' => [
                'code' => "<?php\nsession_module_name(null, 1);\n",
                'needle' => 'session_module_name() expects at most 1 argument, 2 given',
            ],
            'session_commit' => [
                'code' => "<?php\nsession_commit(1);\n",
                'needle' => 'session_commit() expects exactly 0 arguments, 1 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30684_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30684_ex_'.$label.'_'.getmypid().'.bin';
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
                if ('session_commit' === $label) {
                    $this->assertStringNotContainsString('session_write_close()', $joined, $label);
                }
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
        $src = sys_get_temp_dir().'/phpc_30684_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30684_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    session_id(null, 1);
    echo "session_id NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'session_id ', $e->getMessage(), "\n";
}
try {
    session_name(null, 1);
    echo "session_name NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'session_name ', $e->getMessage(), "\n";
}
try {
    session_module_name(null, 1);
    echo "session_module_name NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'session_module_name ', $e->getMessage(), "\n";
}
try {
    session_commit(1);
    echo "session_commit NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'session_commit ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "session_id session_id() expects at most 1 argument, 2 given\n"
            ."session_name session_name() expects at most 1 argument, 2 given\n"
            ."session_module_name session_module_name() expects at most 1 argument, 2 given\n"
            ."session_commit session_commit() expects exactly 0 arguments, 1 given\n"
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
