<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: hash() non-crypto digests match Zend (#34829) — NestedJIT HashCryptoJitHelper.
 *
 * @see php-src ext/hash/hash.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34829HashNonCryptoAotTest extends TestCase
{
    private const EXPECT = "73bb8c64\n352441c2\n364b3fb7\n024d0127\n439c2f4b\n1a47e90b\n900150983cd24fb0d6963f7d28e17f72\n";

    public function testHelperRuntimeInlineOnlyListsHashCryptoHash(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            'hashcryptojithelper::hash',
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT HashCryptoJitHelper::hash — prelinked unit.o is EVP-only (#34829)'
        );
    }

    public function testVmHashNonCryptoRepro(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34829_hash_noncrypto_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34829_hash_noncrypto_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotHashNonCryptoMatchesZend(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34829_hash_noncrypto_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aot_hash_34829_'.getmypid().'.bin';
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
