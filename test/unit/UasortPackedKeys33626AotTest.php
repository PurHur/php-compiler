<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: uasort()/ArrayObject::uasort() on packed lists must preserve keys (#33626).
 *
 * @group llvm
 * @group aot
 */
final class UasortPackedKeys33626AotTest extends TestCase
{
    public function testPackedUasortMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33626_uasort_packed_keys.php');
    }

    public function testUsortKeyedLlvmStringifiesIntKeysBeforeWriteback(): void
    {
        $root = dirname(__DIR__, 2);
        $llvm = (string) file_get_contents($root.'/lib/JIT/UsortKeyedLlvm.php');
        $this->assertStringContainsString('stringifyIntegerPairKeys', $llvm);
        $this->assertStringContainsString('reorderKeyedPairs', $llvm);
        $this->assertStringContainsString('#33626', $llvm);
        $this->assertStringContainsString('JitNativeString::formatIndexKey', $llvm);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $this->assertSame("1:1|2:2|0:3|\n1:1|2:2|0:3|\n1:1|2:2|0:3|\na1b2\n", $zend."\n");
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
        $bin = sys_get_temp_dir().'/uasort_33626_'.getmypid().'_'.md5($src);
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
