<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::format('U.u') must not drop microseconds (#34476, re-#26936 / #33930).
 *
 * php-src: ext/date/php_date.c — php_format_date
 *
 * @group llvm
 * @group aot
 */
final class Issue34476DateTimeFormatUuAotTest extends TestCase
{
    public function testJitDateBakesUuCivilLiteral(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitDate.php');
        $this->assertStringContainsString('#34476', $source);
        $fn = strpos($source, 'public static function tryFormatCivilLiteral');
        $this->assertNotFalse($fn);
        $chunk = substr($source, $fn, 3500);
        $this->assertStringContainsString("'U.u' === \$fmtLit", $chunk);
        $this->assertStringContainsString('%lld.%06lld', $chunk);
    }

    public function testAotFormatUuMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDateTimeCreateFromTimestamp()) {
                $this->markTestSkipped('createFromTimestamp unavailable under PROFILE=8.4');
            }
            $root = dirname(__DIR__, 2);
            $src = $root.'/test/repro/issue_34476_datetime_format_Uu_aot.php';
            $bin = sys_get_temp_dir().'/phpc_uu_34476_'.getmypid().'.bin';
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_PROFILE=8.4 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $expect = "1700000000.000000\n1700000000.500000\n500000\n";
            try {
                for ($i = 0; $i < 5; ++$i) {
                    $runOut = [];
                    exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                    $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                    $this->assertSame($expect, implode("\n", $runOut)."\n", 'run '.($i + 1));
                }
            } finally {
                @unlink($bin);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
