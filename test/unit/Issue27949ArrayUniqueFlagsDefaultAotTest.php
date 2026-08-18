<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * array_unique() omitted $flags under AOT — SORT_STRING default (#27949).
 *
 * @group llvm
 * @group aot
 */
final class Issue27949ArrayUniqueFlagsDefaultAotTest extends TestCase
{
    public function testAotOmittedFlagsUsesSortString(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_27949_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_27949_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$a = array('1', 1, 2);
$u = array_unique($a);
echo count($u), "\n";
PHP);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertSame('2', trim(implode('', $runOut)));
        @unlink($src);
        @unlink($bin);
    }
}
