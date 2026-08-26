<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stream_context_create thin ABI must scope HashTableMergeLlvm into the create/merge
 * functions — not app main (Module.php:180 cross-function args/BBs) (#35167 / #27211).
 *
 * @see php-src ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_context_create)
 *
 * @group llvm
 * @group aot
 */
final class StreamContextCreate35167AotTest extends TestCase
{
    private const EXPECT = "eo1\n";

    public function testThinAotScopesMergeIntoCreateAbi(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamContextThinAot.php');
        $this->assertStringContainsString('#35167', $src);
        $this->assertStringContainsString('scopeLoweringToFunction', $src);
        $this->assertStringContainsString('__phpc_stream_context_create', $src);
        $this->assertStringContainsString('__phpc_stream_context_merge_options', $src);
        $createPos = strpos($src, 'function implementCreate');
        $mergePos = strpos($src, 'function implementMergeOptions');
        $this->assertNotFalse($createPos);
        $this->assertNotFalse($mergePos);
        $createChunk = substr($src, $createPos, $mergePos - $createPos);
        $this->assertStringContainsString('scopeLoweringToFunction', $createChunk);
        $mergeEnd = strpos($src, 'function implementGetOptions', $mergePos);
        $this->assertNotFalse($mergeEnd);
        $mergeChunk = substr($src, $mergePos, $mergeEnd - $mergePos);
        $this->assertStringContainsString('scopeLoweringToFunction', $mergeChunk);
    }

    public function testVmStreamContextCreate(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_stream_context_create_35167.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_stream_context_create_35167.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotStreamContextCreate(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_stream_context_create_35167.php';
        $bin = sys_get_temp_dir().'/phpc_aot_sctx_35167_'.getmypid().'.bin';
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

    public function testNoNewRuntimeCForStreamContextCreateScope(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/stream_context_create.c',
            'must not add stream_context_create.c for #35167 — PHP thin ABI scope only'
        );
    }
}
