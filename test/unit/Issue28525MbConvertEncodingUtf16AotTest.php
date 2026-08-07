<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_convert_encoding() UTF-16BE/LE (#28525).
 *
 * @group llvm
 * @group aot
 */
final class Issue28525MbConvertEncodingUtf16AotTest extends TestCase
{
    public function testAotUtf16Encode(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28525_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_28525_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
echo bin2hex(mb_convert_encoding('A', 'UTF-16BE', 'UTF-8')), "\n";
echo bin2hex(mb_convert_encoding('A', 'UTF-16LE', 'UTF-8')), "\n";
echo bin2hex(mb_convert_encoding('あ', 'UTF-16BE', 'UTF-8')), "\n";
echo bin2hex(mb_convert_encoding('😀', 'UTF-16LE', 'UTF-8')), "\n";
echo bin2hex(mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8')), "\n";
PHP);
        try {
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    "0041\n4100\n3042\n3dd800de\n636166e9\n",
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
