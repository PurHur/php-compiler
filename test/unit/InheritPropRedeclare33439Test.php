<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: child property redeclare must not fatal as trait composition (#33439).
 */
final class InheritPropRedeclare33439Test extends TestCase
{
    public function testAotMatchesZendForPublicProtectedPrivateRedeclare(): void
    {
        $repro = dirname(__DIR__).'/repro/issue_33439_inherit_prop_redeclare.php';
        $this->assertFileExists($repro);

        $zend = $this->capture(static function () use ($repro): void {
            include $repro;
        });
        $this->assertSame("b,b,a,b\n", $zend, 'Zend reference');

        $bin = sys_get_temp_dir().'/phpc_33439_'.getmypid().'.bin';
        $compileCmd = sprintf(
            'php %s -o %s %s 2>&1',
            escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php'),
            escapeshellarg($bin),
            escapeshellarg($repro)
        );
        exec($compileCmd, $compileOut, $compileRc);
        $this->assertSame(
            0,
            $compileRc,
            "AOT compile failed:\n".implode("\n", $compileOut)
        );
        $this->assertFileExists($bin);

        exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
        @unlink($bin);
        $this->assertSame(0, $aotRc, "AOT run failed:\n".implode("\n", $aotOut));
        $this->assertSame($zend, implode("\n", $aotOut).([] === $aotOut ? '' : "\n"));
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }
}
