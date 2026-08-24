<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: generator throw after yield must surface on foreach / next() (#34455).
 *
 * @group llvm
 * @group aot
 */
final class Issue34455GeneratorThrowAfterYieldAotTest extends TestCase
{
    private const EXPECT = "V:1\n"
        ."caught:x\n"
        ."cur:1\n"
        ."caught_next:x\n";

    public function testVmMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34455_generator_throw_after_yield.php';
        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>/dev/null', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame(self::EXPECT, implode("\n", $zendOut)."\n");

        $vmOut = [];
        exec(
            escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>/dev/null',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame(self::EXPECT, implode("\n", $vmOut)."\n");
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34455_generator_throw_after_yield.php';
        $bin = sys_get_temp_dir().'/phpc_34455_'.getmypid().'.bin';

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>/dev/null', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame(self::EXPECT, implode("\n", $zendOut)."\n");

        try {
            $compileOut = [];
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($bin);
        }
    }
}
