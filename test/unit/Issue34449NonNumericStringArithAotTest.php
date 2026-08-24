<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: int⊙non-numeric string arithmetic TypeError (#34449).
 *
 * @group llvm
 * @group aot
 */
final class Issue34449NonNumericStringArithAotTest extends TestCase
{
    private const EXPECT = "binop:Unsupported operand types: int * string\n"
        ."strleft:Unsupported operand types: string * int\n"
        ."mul:caught\n"
        ."add:caught\n"
        ."typed:caught\n"
        ."int(10)\n"
        ."int(5)\n"
        ."DONE\n";

    public function testVmMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34449_nonnumeric_string_arith.php';
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
        $src = $root.'/test/repro/issue_34449_nonnumeric_string_arith.php';
        $bin = sys_get_temp_dir().'/phpc_34449_'.getmypid().'.bin';

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
