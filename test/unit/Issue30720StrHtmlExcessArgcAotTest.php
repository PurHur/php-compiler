<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: str_word_count / htmlspecialchars_decode / get_html_translation_table
 * excess argc → ArgumentCountError (#30720).
 *
 * php-src: ext/standard/string.c / html.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30720StrHtmlExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30720_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30720_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    str_word_count('a', 0, '', 4);
    echo "swc_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'swc_hi ', $e->getMessage(), "\n";
}
try {
    str_word_count();
    echo "swc_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'swc_lo ', $e->getMessage(), "\n";
}
try {
    htmlspecialchars_decode('&lt;', ENT_QUOTES, 3);
    echo "hsd_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hsd_hi ', $e->getMessage(), "\n";
}
try {
    htmlspecialchars_decode();
    echo "hsd_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hsd_lo ', $e->getMessage(), "\n";
}
try {
    get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES, 'UTF-8', 4);
    echo "ghtt_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ghtt_hi ', $e->getMessage(), "\n";
}
$swc = str_word_count('hello world');
echo (2 === $swc) ? "ok_swc\n" : "bad_swc\n";
$hsd = htmlspecialchars_decode('&lt;');
echo ('<' === $hsd) ? "ok_hsd\n" : "bad_hsd\n";
$ghtt = get_html_translation_table();
echo (is_array($ghtt) && isset($ghtt['<'])) ? "ok_ghtt\n" : "bad_ghtt\n";
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
                    "swc_hi str_word_count() expects at most 3 arguments, 4 given\n"
                    ."swc_lo str_word_count() expects at least 1 argument, 0 given\n"
                    ."hsd_hi htmlspecialchars_decode() expects at most 2 arguments, 3 given\n"
                    ."hsd_lo htmlspecialchars_decode() expects at least 1 argument, 0 given\n"
                    ."ghtt_hi get_html_translation_table() expects at most 3 arguments, 4 given\n"
                    ."ok_swc\n"
                    ."ok_hsd\n"
                    ."ok_ghtt\n",
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
