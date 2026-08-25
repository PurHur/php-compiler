<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: dim assign on &...$args must write through to callers (#34790).
 *
 * Skipping compileArg addref on the by-ref variadic HT keeps FETCH_DIM_W from
 * COWing away from the pack that syncByRefVariadicCallers reads (re-#27407 / #34508).
 */
final class Issue34790VariadicBrefDimAssignAotTest extends TestCase
{
    public function testDimAssignByRefVariadicMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/issue_34790_variadic_bref_dim_assign.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame(
            $zend,
            $aot,
            'AOT must match Zend for $args[0]= on &...$args (#34790)'
        );
    }

    public function testMaintainerGapVariadicByrefParamsMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/maintainer_gap_variadic_byref_params.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame(
            $zend,
            $aot,
            'AOT must match Zend for maintainer_gap_variadic_byref_params (#34790)'
        );
    }

    public function testForeachByRefVariadicStillMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_variadic_byref.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame(
            $zend,
            $aot,
            'foreach-by-ref over &...$args must stay green (#34684 / #34790)'
        );
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $work = sys_get_temp_dir().'/phpc_34790_'.bin2hex(random_bytes(4));
        mkdir($work);
        $bin = $work.'/out';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
        $this->assertSame(0, $runCode, implode("\n", $runOut));
        @unlink($bin);
        @rmdir($work);

        return implode("\n", $runOut)."\n";
    }
}
