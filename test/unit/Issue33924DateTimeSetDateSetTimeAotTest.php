<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::setDate/setTime after new must compile and match Zend (#33924).
 *
 * Mutable returnCivilMutation previously passed __object__** to __value__writeObject.
 *
 * @group llvm
 * @group aot
 */
final class Issue33924DateTimeSetDateSetTimeAotTest extends TestCase
{
    public function testReturnCivilMutationBoxesObjectPtr(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateMutation.php'
        );
        $this->assertStringContainsString('#33924', $source);
        $this->assertMatchesRegularExpression(
            '/function returnCivilMutation\b[\s\S]*?boxObjectPtr\(\$context, \$dtObj\)/',
            $source
        );
    }

    public function testAotSetDateAfterNewMatchesZend(): void
    {
        $this->assertAotReproMatchesZend('issue_33924_datetime_setdate_aot.php', 'setdate');
    }

    public function testAotSetTimeAfterNewMatchesZend(): void
    {
        $this->assertAotReproMatchesZend('issue_33924_datetime_settime_aot.php', 'settime');
    }

    private function assertAotReproMatchesZend(string $reproBasename, string $tag): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproBasename;
        $bin = sys_get_temp_dir().'/phpc_33924_'.$tag.'_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
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
