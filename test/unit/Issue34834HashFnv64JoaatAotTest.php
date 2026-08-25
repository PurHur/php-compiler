<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: hash() fnv164/fnv1a64/joaat match Zend (#34834) — NestedJIT HashNonCryptoJitHelper.
 *
 * @see php-src ext/hash/hash_fnv.c, hash_joaat.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34834HashFnv64JoaatAotTest extends TestCase
{
    private const EXPECT = "d8dcca186bafadcb\ne71fa2190541574b\ned131f5b\n73bb8c64\n";

    public function testVmRepro(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34834_hash_fnv64_joaat_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34834_hash_fnv64_joaat_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34834_hash_fnv64_joaat_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aot_hash_34834_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
