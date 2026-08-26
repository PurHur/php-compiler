<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: base64 NestedJIT binary encode/decode + openssl_decrypt(base64) (#35051 / re-#34800).
 *
 * @see php-src ext/standard/base64.c
 * @see php-src ext/openssl/openssl.c
 *
 * @group llvm
 * @group aot
 */
final class Base64NestedJitBinary35051AotTest extends TestCase
{
    private const EXPECT = "enc=gIGC\nenc_ok=true\ndec_ok=true\npng_enc_match=true\nopenssl_b64='hi'\nopenssl_raw='hi'\n";

    public function testHelperUsesStrlenAndSmallShifts(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/Base64JitHelper.php');
        $this->assertStringContainsString('#35051', $src);
        $this->assertStringContainsString('\\strlen($data)', $src);
        $this->assertStringContainsString('($b0 >> 2) & 63', $src);
        $this->assertStringContainsString('(($b0 << 4) & 0x30)', $src);
        $this->assertStringContainsString('(($v0 << 2) | ($v1 >> 4))', $src);
        $this->assertStringNotContainsString('<< 16', $src);
        $this->assertStringNotContainsString('while (isset($data[$len]))', $src);
        $this->assertStringNotContainsString('$data[$i + 1]', $src);
        // Executable packing must use small shifts — not multiply/intdiv (docblocks may name the old forms).
        $this->assertDoesNotMatchRegularExpression('/\$n = \(\$b0 \* 65536\)/', $src);
        $this->assertDoesNotMatchRegularExpression('/intdiv\(\$n, 262144\)/', $src);
        $this->assertDoesNotMatchRegularExpression('/\$buf = \$d \* 4;/', $src);
        $this->assertDoesNotMatchRegularExpression('/intdiv\(\$d, 16\)/', $src);
        $this->assertDoesNotMatchRegularExpression('/intdiv\(\$d, 4\)/', $src);

        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            'base64jithelper::encodeargv',
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT encodeArgv — prelinked unit.o corrupts binary (#34800/#35051)'
        );
    }

    public function testVmBase64NestedJitBinary(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_base64_nestedjit_binary.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_base64_nestedjit_binary.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotBase64NestedJitBinary(): void
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
