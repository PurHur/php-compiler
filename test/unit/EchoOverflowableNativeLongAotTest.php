<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * {main} `echo $s` after overflowable ±/× chains must print the local stack box,
 * not an empty script-global sidecar (#36386 leftover of #37051).
 *
 * `print` / `var_dump` already matched Zend; `echo` preferred echoScriptGlobalName.
 * php-src: Zend/zend_vm_def.h ZEND_ECHO.
 *
 * @group aot-lint
 */
final class EchoOverflowableNativeLongAotTest extends TestCase
{
    public function testEchoAfterMulAddChainMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = 0;
        $s = $s + 5 * 2 - 1;
        echo $s, "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'echo_ov_const');
    }

    public function testEchoAfterLoopMulAddChainMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = 0;
        for ($i = 0; $i < 10; ++$i) {
            $s = $s + $i * 2 - 1;
        }
        echo $s, "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'echo_ov_loop');
    }

    public function testEchoAfterOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = PHP_INT_MAX;
        $x = $x + 1;
        echo $x, "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'echo_ov_promote');
    }

    private function assertAotMatchesZend(string $src, string $tag): void
    {
        $path = sys_get_temp_dir().'/phpc_'.$tag.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_'.$tag.'_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zend, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zend));
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aot));
            $this->assertSame($zend, $aot);
            $this->assertNotSame([''], $aot, 'echo must not print empty for overflowable native-long');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
