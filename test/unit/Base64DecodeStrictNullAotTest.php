<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: base64_decode(null $strict) under strict_types → "null given" not "mixed given" (#29867).
 *
 * @see php-src ext/standard/base64.c Z_PARAM_BOOL
 *
 * @group llvm
 * @group aot
 */
final class Base64DecodeStrictNullAotTest extends TestCase
{
    private const EXPECT = "ok:base64_decode(): Argument #2 (\$strict) must be of type bool, null given\n";

    public function testVmMatchesZendFixture(): void
    {
        $src = dirname(__DIR__).'/repro/maintainer_gap_base64_decode_strict_null.php';
        $this->assertSame($this->runPhp($src), $this->runVm($src));
    }

    public function testAotMatchesZendFixture(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = dirname(__DIR__).'/repro/maintainer_gap_base64_decode_strict_null.php';
        $this->assertSame($this->runPhp($src), $this->runAot($src));
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $out,
            $rc
        );
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/b64_strict_null_'.getmypid().'.bin';
        $cwd = getcwd();
        chdir($root);
        try {
            exec(
                'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '
                .escapeshellarg($src).' 2>&1',
                $compOut,
                $compRc
            );
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut)."\n";
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
