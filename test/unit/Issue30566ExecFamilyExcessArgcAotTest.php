<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: exec/system/passthru/shell_exec excess argc → ArgumentCountError (#30566).
 *
 * php-src: ext/standard/exec.c
 *
 * Happy-path AOT for process execution is covered elsewhere; this guard only
 * asserts the excess-argc catchable path (#30566).
 *
 * @group llvm
 * @group aot
 */
final class Issue30566ExecFamilyExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30566_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30566_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    shell_exec('true', 1);
    echo "shell_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'shell_hi ', $e->getMessage(), "\n";
}
try {
    shell_exec();
    echo "shell_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'shell_lo ', $e->getMessage(), "\n";
}
try {
    $r = 0;
    system('true', $r, 1);
    echo "system NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'system ', $e->getMessage(), "\n";
}
try {
    $r = 0;
    passthru('true', $r, 1);
    echo "passthru NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'passthru ', $e->getMessage(), "\n";
}
try {
    $o = [];
    $r = 0;
    exec('true', $o, $r, 1);
    echo "exec NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'exec ', $e->getMessage(), "\n";
}
try {
    exec();
    echo "exec_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'exec_lo ', $e->getMessage(), "\n";
}
PHP);
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
                $this->assertSame(
                    "shell_hi shell_exec() expects exactly 1 argument, 2 given\n"
                    ."shell_lo shell_exec() expects exactly 1 argument, 0 given\n"
                    ."system system() expects at most 2 arguments, 3 given\n"
                    ."passthru passthru() expects at most 2 arguments, 3 given\n"
                    ."exec exec() expects at most 3 arguments, 4 given\n"
                    ."exec_lo exec() expects at least 1 argument, 0 given\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
                $joined = implode("\n", $runOut);
                $this->assertStringNotContainsString('LogicException', $joined);
                $this->assertStringNotContainsString('accepts one', $joined);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
