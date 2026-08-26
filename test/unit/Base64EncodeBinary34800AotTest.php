<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: base64_encode(binary) matches Zend (#34800 / peer #26890 / MbMimeheader b64Encode).
 *
 * @see php-src ext/standard/base64.c
 *
 * @group llvm
 * @group aot
 */
final class Base64EncodeBinary34800AotTest extends TestCase
{
    private const EXPECT = "enc_match=true\nroundtrip=true\nascii=true\n";

    public function testHelperEncodeWalkIsNestedJitSafe(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/Base64JitHelper.php');
        $this->assertStringContainsString('#34800', $src);
        $this->assertStringContainsString('\\strlen($data)', $src);
        $this->assertStringContainsString('$b0 >> 2', $src);
        $this->assertStringContainsString('(($b0 & 3) << 4)', $src);
        $this->assertStringNotContainsString('$data[$i + 1]', $src);
        $this->assertStringNotContainsString('$data[$i + 2]', $src);
        $this->assertStringNotContainsString('<< 16', $src);
        $this->assertStringNotContainsString('* 65536', $src);

        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            'base64jithelper::encodeargv',
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT encodeArgv — prelinked unit.o corrupts binary (#34800)'
        );
    }

    public function testVmBase64EncodeBinary(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34800_base64_encode_binary.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34800_base64_encode_binary.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotBase64EncodeBinary(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34800_base64_encode_binary.php';
        $bin = sys_get_temp_dir().'/phpc_aot_b64enc_34800_'.getmypid().'.bin';
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
