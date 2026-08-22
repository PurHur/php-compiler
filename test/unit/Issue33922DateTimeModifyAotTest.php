<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::modify after construct — no requireDateTimeObject structGep (#33922).
 *
 * @group llvm
 * @group aot
 */
final class Issue33922DateTimeModifyAotTest extends TestCase
{
    public function testModifyUsesCompileTimeInstantAndLoadObjectFromArg(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateMutation.php'
        );
        $this->assertStringContainsString('#33922', $source);
        $this->assertStringContainsString(
            'resolveCompileTimeInstant($context, $args[0])',
            $source
        );
        $this->assertStringContainsString(
            'ReflectionSetup::loadObjectFromArg($context, $args[0])',
            $source
        );
    }

    public function testAotModifyMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33922_datetime_modify_aot.php';
        $bin = sys_get_temp_dir().'/phpc_modify_33922_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $expected = "day=2020-01-02\nfrac=2020-01-01 00:00:01.500000\n";
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
