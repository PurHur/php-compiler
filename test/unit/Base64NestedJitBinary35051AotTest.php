<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: NestedJIT base64_encode/decode binary + openssl_decrypt base64 (re-#34800).
 *
 * @see php-src ext/standard/base64.c
 * @see php-src ext/openssl/openssl.c
 *
 * @group llvm
 * @group aot
 */
final class Base64NestedJitBinary35051AotTest extends TestCase
{
    private const EXPECT = "ascii=true\nbin=true\nenc_match=true\nodec=26b0e318760af42bd1af42f4a75c1c90\nopenssl='hi'\n";

    public function testHelperUsesSmallShiftPacking(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/Base64JitHelper.php');
        $this->assertStringContainsString('\\strlen($data)', $src);
        $this->assertStringContainsString('$b0 >> 2', $src);
        $this->assertStringContainsString('(($b0 & 3) << 4)', $src);
        $this->assertStringContainsString('($buf << 2) | ($d >> 4)', $src);
        $this->assertStringNotContainsString('* 65536', $src);
        $this->assertStringNotContainsString('<< 16', $src);
    }

    public function testAotBase64BinaryAndOpensslDecrypt(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_base64_nestedjit_binary.php';
        $bin = sys_get_temp_dir().'/phpc_aot_b64_35051_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
