<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mbstring excess argc catchable under try (#30786).
 *
 * php-src: ext/mbstring/mbstring.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30786MbExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30786_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30786_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    mb_str_split('ab', 1, null, 1);
    echo "split NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'split ', $e->getMessage(), "\n";
}
try {
    mb_convert_case('a', MB_CASE_UPPER, 'UTF-8', 1);
    echo "case NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'case ', $e->getMessage(), "\n";
}
try {
    mb_scrub('a', 'UTF-8', 1);
    echo "scrub NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'scrub ', $e->getMessage(), "\n";
}
try {
    mb_substr_count('aba', 'a', 'UTF-8', 1);
    echo "count NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'count ', $e->getMessage(), "\n";
}
try {
    mb_str_split();
    echo "split_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'split_lo ', $e->getMessage(), "\n";
}
echo 'ok:', implode(',', mb_str_split('ab', 1)), "\n";
PHP);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    "split mb_str_split() expects at most 3 arguments, 4 given\n"
                    ."case mb_convert_case() expects at most 3 arguments, 4 given\n"
                    ."scrub mb_scrub() expects at most 2 arguments, 3 given\n"
                    ."count mb_substr_count() expects at most 3 arguments, 4 given\n"
                    ."split_lo mb_str_split() expects at least 1 argument, 0 given\n"
                    ."ok:a,b\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
