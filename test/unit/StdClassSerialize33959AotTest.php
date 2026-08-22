<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(stdClass) must match Zend — no SIGSEGV from SPL phi (#33959).
 *
 * @group llvm
 * @group aot
 */
final class StdClassSerialize33959AotTest extends TestCase
{
    public function testStdClassSerializeMatchesZend(): void
    {
        $src = __DIR__.'/../repro/stdclass_serialize_aot_33959.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('O:8:"stdClass":0:{}', $aot);
        $this->assertStringContainsString('s:1:"x";i:2;', $aot);
        $this->assertStringContainsString('d:1.5;', $aot);
        $this->assertStringContainsString('b:1;', $aot);
        $this->assertSame($zend, $aot);
    }

    public function testSplDispatchOmitsUnregisteredPhiArms(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitSerialize.php'
        );
        $this->assertStringContainsString('#33959', $src);
        $this->assertStringContainsString('encodePublicObjectProps', $src);
        // SOS/Fixed/Array arms are gated on registered ids before compileSerialize.
        $this->assertMatchesRegularExpression(
            '/if \(null !== \$sosId\) \{.*?compileSerialize/s',
            $src
        );
        $this->assertMatchesRegularExpression(
            '/if \(null !== \$fixedId\) \{.*?compileSerialize/s',
            $src
        );
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/stdclass_ser_33959_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
                $out = [];
                $rc = 0;
                exec(escapeshellarg($bin).' 2>&1', $out, $rc);
                $this->assertSame(0, $rc, 'run '.($i + 1).': '.implode("\n", $out));
                ++$matched;
            }
            $this->assertSame(5, $matched);

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
