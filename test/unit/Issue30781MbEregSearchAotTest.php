<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ereg_search_init()/mb_ereg_search()/mb_regex_encoding() (#30781).
 *
 * @group llvm
 * @group aot
 */
final class Issue30781MbEregSearchAotTest extends TestCase
{
    public function testAotMbEregSearchAndRegexEncoding(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_30781_mb_ereg_search_aot.php';
        $bin = sys_get_temp_dir().'/phpc_30781_'.getmypid().'.bin';
        $expected = "yes\nok\nenc=UTF-8\nset=true\nenc2=UTF-8\n";

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame($expected, implode("\n", $zendOut)."\n");

        try {
            foreach ([0, 1] as $helperO) {
                $compileOut = [];
                $compile = 'PHP_COMPILER_HELPER_RUNTIME_O='.$helperO.' '
                    .escapeshellarg(PHP_BINARY).' '
                    .escapeshellarg($root.'/bin/compile.php')
                    .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
                exec($compile, $compileOut, $compileRc);
                $this->assertSame(
                    0,
                    $compileRc,
                    'HELPER_RUNTIME_O='.$helperO."\n".implode("\n", $compileOut)
                );
                $this->assertFileExists($bin);
                for ($i = 0; $i < 3; ++$i) {
                    $runOut = [];
                    exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                    $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                    $this->assertSame($expected, implode("\n", $runOut)."\n", 'HELPER_O='.$helperO.' run '.$i);
                }
            }
        } finally {
            @unlink($bin);
        }
    }
}
