<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: unserialize(DateInterval) restores Zend member wire (#34602 / residual #34599).
 *
 * @see php-src ext/date/php_date.c — DateInterval::__unserialize
 *
 * @group llvm
 * @group aot
 */
final class Issue34602DateIntervalUnserializeAotTest extends TestCase
{
    public function testAotMatchesZendSerializeAndLiteralWire(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_34602_dateinterval_unserialize_aot.php'
        );
    }

    public function testNestedJitRestoreWired(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents(
            $root.'/ext/standard/UnserializeDateWireNestedJitHelper.php'
        );
        $this->assertStringContainsString('parseDateInterval', $helper);
        $this->assertStringContainsString('#34602', $helper);
        $bridge = (string) file_get_contents($root.'/lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('compileDateIntervalRestore', $bridge);
        $jit = (string) file_get_contents($root.'/lib/VM/DateUnserializeJitHelper.php');
        $this->assertStringContainsString('compileDateIntervalRestore', $jit);
        $this->assertStringContainsString('#34602', $jit);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/unser_34602_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            $out = [];
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $chunk = implode("\n", $runOut)."\n";
                if (0 === $i) {
                    $out = $runOut;
                } else {
                    $this->assertSame(implode("\n", $out)."\n", $chunk, 'run '.($i + 1));
                }
            }

            return implode("\n", $out)."\n";
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
