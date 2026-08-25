<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: crc32() matches Zend (#34824) — NestedJIT Crc32JitHelper into user module.
 *
 * @see php-src ext/standard/crc32.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34824Crc32AotTest extends TestCase
{
    private const EXPECT = "891568578\n2356372769\n3632233996\n0\n";

    public function testHelperRuntimeInlineOnlyListsCrc32Argv(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            'crc32jithelper::crc32argv',
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT crc32Argv — prelinked unit.o miscomputes (#34824)'
        );
        $this->assertStringContainsString(
            'crc32jithelper::crc32cargv',
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT crc32cArgv (#34824)'
        );
    }

    public function testVmCrc32Repro(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34824_crc32_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34824_crc32_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotCrc32MatchesZend(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34824_crc32_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aot_crc32_34824_'.getmypid().'.bin';
        // Prefer default helper-runtime path — USER_SCRIPT_INLINE_ONLY NestedJITs fixed helper.
        $compile = escapeshellarg(PHP_BINARY).' '
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
