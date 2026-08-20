<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mhash*() leftover of #14975 (#32930).
 *
 * @see php-src ext/hash/hash.c PHP_FUNCTION(mhash)
 *
 * @group aot-lint
 */
final class MhashAotTest extends TestCase
{
    private const EXPECTED = "count=41|name=MD5|block=16|hex=5d41402abc4b2a76b9719d911017c592|s2k=aa7d368c208d9a890bdacc9604053e8c|invalid=1\n";

    public function testVmMhashMatchesFixture(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32930_mhash_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32930_mhash_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotMhashMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32930_mhash_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32930_mhash_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_mhash_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testCallNoLongerThrowsJitUnimplemented(): void
    {
        $root = dirname(__DIR__, 2).'/ext/hash';
        foreach ([
            'mhash.php' => 'JitMhash::mhash',
            'mhash_count.php' => 'JitMhash::count',
            'mhash_get_hash_name.php' => 'JitMhash::getHashName',
            'mhash_get_block_size.php' => 'JitMhash::getBlockSize',
            'mhash_keygen_s2k.php' => 'JitMhash::keygenS2k',
        ] as $file => $needle) {
            $src = (string) file_get_contents($root.'/'.$file);
            $this->assertStringNotContainsString('not implemented for JIT', $src, $file);
            $this->assertStringContainsString($needle, $src, $file);
        }
        $this->assertFileExists($root.'/JitMhash.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/mhash.c');
    }
}
