<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for idate()/getdate() (#26900).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(idate) / PHP_FUNCTION(getdate)
 *
 * @group llvm
 * @group aot
 */
final class IdateGetdateAot26900Test extends TestCase
{
    public function testAotIdateGetdateExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_idate_getdate_26900_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo idate('Y', 1592179200), "\n";
$d = getdate(1577923200);
echo $d['year'].'-'.$d['mon'].'-'.$d['mday'], "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_idate_getdate_26900_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("2020\n2020-1-2\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
