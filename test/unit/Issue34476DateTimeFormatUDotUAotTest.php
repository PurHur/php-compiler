<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::format('U.u') civil snprintf bake (#34476 / ext/date/php_date.c).
 *
 * createFromTimestamp is PHP 8.4 — compare AOT to VM under PROFILE=8.4 (host Zend may be 8.2).
 *
 * @group llvm
 * @group aot
 */
final class Issue34476DateTimeFormatUDotUAotTest extends TestCase
{
    public function testCivilLiteralBakesUDotU(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDate.php'
        );
        $this->assertStringContainsString('#34476', $source);
        $this->assertStringContainsString("if ('U.u' === \$fmtLit)", $source);
        $this->assertStringContainsString('%lld.%06lld', $source);
    }

    public function testAotFormatUDotUMatchesVm(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34476_datetime_format_u_dot_u_aot.php';
        $bin = sys_get_temp_dir().'/phpc_format_uu_34476_'.getmypid().'.bin';
        $env = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 ';
        $compile = $env.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $vmOut = [];
        exec(
            'PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $expected = implode("\n", $vmOut)."\n";
        // Zend php_format_date: int path six zero micros; float path keeps fraction (#34476).
        $this->assertSame(
            "1700000000.000000\n1700000000.500000\n500000\n500000\n",
            $expected
        );

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
