<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_cipher_*_length('') uses Zend "cannot be empty" ValueError text.
 *
 * @see php-src ext/openssl/openssl.c zend_argument_must_not_be_empty_error
 *
 * @group aot-lint
 */
final class OpensslCipherLengthEmptyAlgoAotTest extends TestCase
{
    /**
     * @group llvm
     * @group aot
     */
    public function testAotCipherIvLengthEmptyAlgoMessage(): void
    {
        $this->assertAotMatchesZend($this->root().'/test/repro/maintainer_gap_openssl_cipher_iv_length_empty.php');
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotCipherKeyLengthEmptyAlgoMessage(): void
    {
        $this->assertAotMatchesZend($this->root().'/test/repro/maintainer_gap_openssl_cipher_key_length_empty.php');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function assertAotMatchesZend(string $src): void
    {
        if (!LlvmToolchain::hasLibrary($this->root())) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_ossl_empty_'.md5($src).'_'.getmypid().'.bin';
        $outFile = sys_get_temp_dir().'/phpc_ossl_empty_'.md5($src).'_'.getmypid().'.out';
        $zendOutFile = sys_get_temp_dir().'/phpc_ossl_empty_zend_'.md5($src).'_'.getmypid().'.out';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' >'.escapeshellarg($zendOutFile).' 2>&1');
        $zendOut = (string) file_get_contents($zendOutFile);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($this->root().'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            exec(escapeshellarg($bin).' >'.escapeshellarg($outFile).' 2>&1', $ignored, $runRc);
            $aotOut = (string) file_get_contents($outFile);
            $this->assertSame(0, $runRc, $aotOut);
            $this->assertSame(trim($zendOut), trim($aotOut), "zend=\n{$zendOut}\naot=\n{$aotOut}");
        } finally {
            @unlink($bin);
            @unlink($outFile);
            @unlink($zendOutFile);
        }
    }
}
