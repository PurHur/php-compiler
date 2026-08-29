<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_random_pseudo_bytes negative length is catchable ValueError (not SIGABRT).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_random_pseudo_bytes)
 *
 * @group aot-lint
 */
final class OpensslRandomPseudoBytesValueErrorAotTest extends TestCase
{
    /**
     * @group llvm
     * @group aot
     */
    public function testAotNegativeLengthCatchableValueError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_openssl_random_pseudo_bytes_message.php';
        $bin = sys_get_temp_dir().'/phpc_ossl_rand_neg_'.getmypid().'.bin';
        $outFile = sys_get_temp_dir().'/phpc_ossl_rand_neg_'.getmypid().'.out';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            exec(escapeshellarg($bin).' >'.escapeshellarg($outFile).' 2>&1', $ignored, $runRc);
            $stdout = (string) file_get_contents($outFile);
            $this->assertSame(0, $runRc, $stdout);
            $this->assertStringContainsString(
                'ValueError: openssl_random_pseudo_bytes(): Argument #1 ($length) must be greater than 0',
                $stdout
            );
        } finally {
            @unlink($bin);
            @unlink($outFile);
        }
    }
}
