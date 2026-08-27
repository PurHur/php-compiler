<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_regex_encoding() NestedJIT runtime setter (#35284 leftover of #30781).
 *
 * @see php-src ext/mbstring/php_mbregex.c PHP_FUNCTION(mb_regex_encoding)
 *
 * @group llvm
 * @group aot
 */
final class MbRegexEncodingRuntimeAotTest extends TestCase
{
    public function testAotRuntimeSetterMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_regex_encoding_runtime.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbRegexEncodingJitHelper.php');
        $this->assertStringContainsString('function canonicalizeArgv', $helper);
        $this->assertStringNotContainsString('MbstringEncodingRegistry::', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbRegexEncodingRuntime.php');
        $this->assertStringContainsString('canonicalizeHelper', $runtime);
        $this->assertStringContainsString('MbRegexEncodingJitHelper::canonicalizeArgv', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbRegexEncoding.php');
        $this->assertStringContainsString('MbRegexEncodingRuntime::ensureLinked', $jit);
        $fold = (string) file_get_contents($root.'/ext/mbstring/JitMbEregSearch.php');
        $this->assertStringContainsString('JitMbRegexEncoding::invoke', $fold);
        $this->assertStringNotContainsString(
            'mb_regex_encoding() encoding must be a compile-time string',
            $fold
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_regex_encoding.c');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_regex_encoding_35284_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
