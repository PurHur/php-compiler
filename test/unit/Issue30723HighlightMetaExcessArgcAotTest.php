<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: highlight_string / get_meta_tags excess argc → ArgumentCountError (#30723).
 *
 * php-src: ext/standard/url_scanner_ex.re / basic_functions.c
 *
 * Valid get_meta_tags() AOT still dies with "Current basic block has no parent
 * function" (pre-existing MetaTags helper IR, not this argc change). Excess
 * argc is catchable because it aborts before linking that helper.
 *
 * @group llvm
 * @group aot
 */
final class Issue30723HighlightMetaExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30723_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30723_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    highlight_string('<?php', true, 3);
    echo "hs_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hs_hi ', $e->getMessage(), "\n";
}
try {
    highlight_string();
    echo "hs_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hs_lo ', $e->getMessage(), "\n";
}
try {
    get_meta_tags('/etc/hosts', true, 3);
    echo "gmt_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'gmt_hi ', $e->getMessage(), "\n";
}
try {
    get_meta_tags();
    echo "gmt_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'gmt_lo ', $e->getMessage(), "\n";
}
$html = highlight_string('<?php echo 1;', true);
echo (is_string($html) && '' !== $html) ? "ok_hs\n" : "bad_hs\n";
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
                    "hs_hi highlight_string() expects at most 2 arguments, 3 given\n"
                    ."hs_lo highlight_string() expects at least 1 argument, 0 given\n"
                    ."gmt_hi get_meta_tags() expects at most 2 arguments, 3 given\n"
                    ."gmt_lo get_meta_tags() expects at least 1 argument, 0 given\n"
                    ."ok_hs\n",
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
