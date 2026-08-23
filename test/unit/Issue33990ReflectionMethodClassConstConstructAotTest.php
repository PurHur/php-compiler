<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ReflectionMethod / ReflectionClassConstant __construct seed $class/$name (#33990).
 *
 * @group llvm
 * @group aot
 */
final class Issue33990ReflectionMethodClassConstConstructAotTest extends TestCase
{
    public function testAotConstructSeedsClassNameAndPropertyAttrs(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33990_reflection_method_classconst_construct_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33990_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $expected = "B|m\nm\nB|X\n1\n";
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($bin);
        }
    }
}
