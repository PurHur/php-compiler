<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode(unit enum) matches Zend — must not abort compile (#35294).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeUnitEnumAot35294Test extends TestCase
{
    public function testVmMatchesZend(): void
    {
        $this->assertVmMatchesZend(__DIR__.'/../repro/aot_json_encode_unit_enum_35294.php');
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_json_encode_unit_enum_35294.php');
    }

    private function assertVmMatchesZend(string $src): void
    {
        $root = dirname(__DIR__, 2);
        $zend = [];
        $vm = [];
        exec('php '.escapeshellarg($src).' 2>/dev/null', $zend, $zendRc);
        exec(
            'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>/dev/null',
            $vm,
            $vmRc
        );
        self::assertSame(0, $zendRc);
        self::assertSame(0, $vmRc);
        self::assertSame(implode("\n", $zend), implode("\n", $vm));
    }

    private function assertAotMatchesZend(string $src): void
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_issue_35294_json_unit_enum_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        self::assertSame(0, $compileRc, implode("\n", $compileOut));
        self::assertFileExists($bin);
        try {
            $zend = [];
            exec('php '.escapeshellarg($src).' 2>/dev/null', $zend, $zendRc);
            self::assertSame(0, $zendRc);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            self::assertSame(0, $runRc, implode("\n", $runOut));
            self::assertSame(implode("\n", $zend), implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
