<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: str_replace/str_ireplace NestedJIT matchAt (#36002 leftover of #32621).
 *
 * @see php-src ext/standard/string.c php_str_replace
 *
 * @group llvm
 * @group aot
 */
final class StrReplaceNestedJitMatchAtAotTest extends TestCase
{
    public function testAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/aot_str_replace_nestedjit_matchat.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testHelperUsesRecursiveMatchAt(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/standard/StrReplaceJitHelper.php');
        $this->assertStringContainsString('function matchAt(', $jit);
        $this->assertStringContainsString('function matchAtI(', $jit);
        $this->assertStringContainsString('#36002', $jit);
        // Inner while over needle dims is the NestedJIT miscompare (#36002); recursive match stays.
        $this->assertDoesNotMatchRegularExpression(
            '/while\s*\(\s*\$j\s*<\s*\$needleLen\s*\)/',
            $jit
        );
        $this->assertStringContainsString('self::matchAt(', $jit);
        $this->assertStringContainsString('self::matchAtI(', $jit);
    }

    private function runVm(string $src): string
    {
        return $this->runEnv([], 'bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/sr_matchat_'.getmypid().'_'.md5($src);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            $this->assertSame(0, $crc, implode("\n", $cout));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    /**
     * @param list<string> $env
     */
    private function runEnv(array $env, string $binRel, string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env '.implode(' ', $env).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/'.$binRel).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
