<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: headers_sent() excess argc → ArgumentCountError (#30705).
 *
 * php-src: ext/standard/head.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30705HeadersSentExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30705_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30705_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$f = null;
$l = null;
try {
    headers_sent($f, $l, 'x');
    echo "hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hi ', $e->getMessage(), "\n";
}
try {
    headers_sent($f, $l, 'x', 'y');
    echo "hi4 NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hi4 ', $e->getMessage(), "\n";
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
                    "hi headers_sent() expects at most 2 arguments, 3 given\n"
                    ."hi4 headers_sent() expects at most 2 arguments, 4 given\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
