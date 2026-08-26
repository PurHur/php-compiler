<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: NestedJIT urlencode/rawurlencode/urldecode/rawurldecode must not segfault (#35057).
 *
 * @see php-src ext/standard/url.c
 *
 * @group llvm
 * @group aot
 */
final class UrlencodeNestedJitSegfault35057AotTest extends TestCase
{
    private const EXPECT = "a+b%26c\na%20b%26c\na b&c\na b&c\n~._-\nfoo+bar\nfoo+bar\n";

    public function testHelpersAreSelfContainedNoVmString(): void
    {
        $enc = (string) file_get_contents(__DIR__.'/../../ext/standard/UrlencodeJitHelper.php');
        $dec = (string) file_get_contents(__DIR__.'/../../ext/standard/UrldecodeJitHelper.php');
        $this->assertStringContainsString('\\strlen($data)', $enc);
        $this->assertStringContainsString('\\strlen($data)', $dec);
        $this->assertStringContainsString('0123456789ABCDEF', $enc);
        $this->assertStringNotContainsString('VmString::', $enc);
        $this->assertStringNotContainsString('VmString::', $dec);
        $this->assertStringContainsString('byteOrd', $enc);
        $this->assertStringContainsString('byteAt', $dec);
    }

    public function testAotUrlencodeFamilyMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_urlencode_nestedjit_segfault.php';
        $bin = sys_get_temp_dir().'/phpc_aot_url_35057_'.getmypid().'.bin';
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
