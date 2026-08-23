<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass getStartLine via typed show() thrice (#34186).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getStartLine
 * @see \PHPCompiler\JIT\Builtin\ReflectionClassSourceLocationRuntime
 *
 * @group llvm
 * @group aot
 */
final class Issue34186ReflectionClassSourceLocationShowAotTest extends TestCase
{
    public function testHelperAbiRegisteredInRuntime(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionClassSourceLocationRuntime.php'
        );
        $this->assertStringContainsString('__phpc_refl_class_get_start_line', $source);
        $this->assertStringContainsString('#34186', $source);
        $this->assertStringContainsString('ensureLinked', $source);
    }

    /**
     * @dataProvider reproFiles
     */
    public function testAotShowThriceStable(string $relative, string $expectSubstr): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$relative;
        $this->assertFileExists($src);
        $bin = sys_get_temp_dir().'/phpc_34186_'.md5($relative).'_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = implode("\n", $runOut);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertStringContainsString($expectSubstr, $joined);
                $this->assertStringContainsString('done', $joined);
                $this->assertStringNotContainsString('segfault', strtolower($joined));
            }
        } finally {
            @unlink($bin);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public function reproFiles(): array
    {
        return [
            'multiline' => [
                'test/repro/issue_34186_reflection_class_sourceloc_show_aot.php',
                'a => int ',
            ],
            'compact' => [
                'test/repro/issue_34186_compact_sourceloc_show_aot.php',
                'a => int ',
            ],
            'filename_start_end' => [
                'test/repro/issue_34186_filename_start_end_show_aot.php',
                'el => int ',
            ],
        ];
    }

    public function testVmBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34186_compact_sourceloc_show_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('done', $joined);
    }
}
