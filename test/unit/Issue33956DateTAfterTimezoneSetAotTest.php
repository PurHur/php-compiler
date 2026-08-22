<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: date('T'/'e') after date_default_timezone_set follows runtime zone (#33956).
 *
 * @see php-src ext/date/php_date.c php_format_date tokens T/e
 *
 * @group llvm
 * @group aot
 */
final class Issue33956DateTAfterTimezoneSetAotTest extends TestCase
{
    private const EXPECTED = "true\nEurope/Berlin\nCEST\nEurope/Berlin\n";

    public function testVmDateTAfterTimezoneSet(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33956_date_t_after_timezone_set_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33956_date_t_after_timezone_set_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDateTAfterTimezoneSet(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33956_date_t_after_timezone_set_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33956_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($bin);
        }
    }
}
