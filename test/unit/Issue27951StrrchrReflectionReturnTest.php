<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * strrchr Reflection return string|false (#27951, ext/standard/string.stub.php).
 *
 * @group llvm
 * @group aot
 */
final class Issue27951StrrchrReflectionReturnTest extends TestCase
{
    private const EXPECT = <<<'TXT'
ret:string|false
false
'-def'
TXT;

    public function testVmReproMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/issue_27951_strrchr_reflection_return.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1';
        $out = [];
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertSame(self::EXPECT, implode("\n", $out));
    }

    public function testAotRuntimeMissAndHitUnchanged(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_27951_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_27951_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
echo strrchr('abc', 'z') === false ? "false\n" : "hit\n";
echo strrchr('abc-def', '-'), "\n";
PHP);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("false\n-def\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
