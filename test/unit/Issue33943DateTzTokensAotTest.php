<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT free date() timezone tokens T/e/O/P/r (#33943).
 *
 * @group llvm
 * @group aot
 */
final class Issue33943DateTzTokensAotTest extends TestCase
{
    public function testCivilLiteralBakesUtcTimezoneTokens(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDate.php'
        );
        $this->assertStringContainsString('#33943', $source);
        $this->assertStringContainsString('tryFormatUtcTimezoneLiteral', $source);
        $this->assertStringContainsString('tryFormatCivilRfc2822Utc', $source);
    }

    public function testAotDateTimezoneTokensMatchZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33943_date_tz_tokens_aot.php';
        $bin = sys_get_temp_dir().'/phpc_date_tz_33943_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expected = implode("\n", $zendOut)."\n";

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
