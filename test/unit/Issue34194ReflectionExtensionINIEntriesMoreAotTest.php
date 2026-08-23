<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension getINIEntries for zlib/iconv (#34194).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_getINIEntries
 * @see \PHPCompiler\JIT\Builtin\ReflectionExtensionGetINIEntriesRuntime
 *
 * @group llvm
 * @group aot
 */
final class Issue34194ReflectionExtensionINIEntriesMoreAotTest extends TestCase
{
    private const EXPECT = <<<'TXT'
zlib count=3
iconv count=3
openssl count=2
filter count=2
standard count=14
date count=5
TXT;

    public function testRuntimeBakesZlibIconv(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionGetINIEntriesRuntime.php'
        );
        $this->assertStringContainsString("'zlib'", $source);
        $this->assertStringContainsString("'iconv'", $source);
        $this->assertStringContainsString('#34194', $source);
    }

    public function testAotINIEntriesMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34194_reflection_extension_inientries_more_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34194_ini_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = implode("\n", $runOut);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(self::EXPECT, trim($joined));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testZendBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34194_reflection_extension_inientries_more_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }
}
