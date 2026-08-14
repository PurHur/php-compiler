<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mbstring/iconv excess argc catchable under try (#30891).
 *
 * php-src: ext/mbstring/mbstring.c, ext/iconv/iconv.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30891MbIconvExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30891_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30891_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    mb_strlen('a', 'UTF-8', 1);
    echo "strlen NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'strlen ', $e->getMessage(), "\n";
}
try {
    mb_convert_encoding('a', 'UTF-8', 'UTF-8', 1);
    echo "conv NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'conv ', $e->getMessage(), "\n";
}
try {
    iconv_strlen('a', 'UTF-8', 1);
    echo "iconv_len NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'iconv_len ', $e->getMessage(), "\n";
}
try {
    iconv_substr('abcd', 1, 2, 'UTF-8', 1);
    echo "iconv_sub NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'iconv_sub ', $e->getMessage(), "\n";
}
try {
    iconv_strpos('abcd', 'b', 0, 'UTF-8', 1);
    echo "iconv_pos NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'iconv_pos ', $e->getMessage(), "\n";
}
try {
    mb_strlen();
    echo "strlen_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'strlen_lo ', $e->getMessage(), "\n";
}
echo 'ok:', (string) mb_strlen('ab'), "\n";
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
                    "strlen mb_strlen() expects at most 2 arguments, 3 given\n"
                    ."conv mb_convert_encoding() expects at most 3 arguments, 4 given\n"
                    ."iconv_len iconv_strlen() expects at most 2 arguments, 3 given\n"
                    ."iconv_sub iconv_substr() expects at most 4 arguments, 5 given\n"
                    ."iconv_pos iconv_strpos() expects at most 4 arguments, 5 given\n"
                    ."strlen_lo mb_strlen() expects at least 1 argument, 0 given\n"
                    ."ok:2\n",
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
