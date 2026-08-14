<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: password_get_info() excess argc → ArgumentCountError (#30712).
 *
 * php-src: ext/standard/password.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30712PasswordGetInfoExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30712_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30712_ex_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    password_get_info('x', 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(
                "password_get_info() expects exactly 1 argument, 2 given\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
