<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: uasort preserves keys on packed arrays (#33627).
 *
 * @group llvm
 * @group aot
 */
final class UasortPackedPreserveKeys33627AotTest extends TestCase
{
    public function testPackedUasortMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/uasort_packed_preserve_keys_33627.php');
    }

    public function testStringifyWiredOnUsortWriteback(): void
    {
        $root = dirname(__DIR__, 2);
        $usort = (string) file_get_contents($root.'/lib/JIT/UsortKeyedLlvm.php');
        $this->assertStringContainsString('ValueSortKeyedLlvm::stringifyIntegerPairKeys', $usort);
        $this->assertStringContainsString('#33627', $usort);
        $vsort = (string) file_get_contents($root.'/lib/JIT/ValueSortKeyedLlvm.php');
        $this->assertStringContainsString('public static function stringifyIntegerPairKeys', $vsort);
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

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/uasort_33627_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
