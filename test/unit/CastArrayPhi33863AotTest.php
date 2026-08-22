<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: (array) cast PHI types + value-box kind mask (#33863).
 *
 * @group llvm
 * @group aot
 */
final class CastArrayPhi33863AotTest extends TestCase
{
    public function testCastArrayMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33863_cast_array_phi.php');
    }

    public function testSharedUnwrapsSplBoxBeforeHashtablePhi(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/CastArrayShared.php');
        $this->assertStringContainsString('#33863', $src);
        $this->assertStringContainsString('__value__readHashtable', $src);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $src);
        $this->assertStringContainsString('TYPE_HASHTABLE & 0x7f', $src);
    }

    public function testValueBoxMasksKindAndAcceptsJitHashtable(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/CastArrayValueBoxJit.php');
        $this->assertStringContainsString('#33863', $src);
        $this->assertStringContainsString('0x7f', $src);
        $this->assertStringContainsString('TYPE_HASHTABLE & 0x7f', $src);
        $this->assertStringContainsString('getInsertBlock()', $src);
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
        $bin = sys_get_temp_dir().'/ao_33863_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
