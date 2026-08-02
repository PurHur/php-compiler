<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT get_debug_type() array/null/stdClass (#26885).
 *
 * @group llvm
 * @group aot
 */
final class GetDebugTypeAotTest extends TestCase
{
    public function testAotGetDebugTypeArrayNullStdClass(): void
    {
        $prev = getenv('PHP_COMPILER_HELPER_RUNTIME_O');
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        try {
            $this->assertAotPrints(
                dirname(__DIR__, 2).'/test/repro/issue_26885_get_debug_type_aot.php',
                "array\nnull\nstdClass\n"
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            } else {
                putenv('PHP_COMPILER_HELPER_RUNTIME_O='.$prev);
            }
        }
    }

    public function testBoxedPathUsesValuePtrNotLoadValue(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/get_debug_type.php');
        $this->assertStringContainsString('JitValueBox::valuePtrFromVariable', $src);
        $this->assertStringNotContainsString(
            '$loaded = $context->helper->loadValue($arg);',
            $src
        );
    }

    private function assertAotPrints(string $src, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $out = sys_get_temp_dir().'/phpc_gdt_26885_'.getmypid();
        @unlink($out);
        $cmd = [PHP_BINARY, '-d', 'memory_limit=4G', $root.'/bin/compile.php', '-o', $out, $src];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stdout."\n".$stderr));
        $this->assertFileExists($out);
        $run = proc_open([$out], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rpipes, $root);
        $this->assertIsResource($run);
        $body = stream_get_contents($rpipes[1]);
        $err = stream_get_contents($rpipes[2]);
        fclose($rpipes[1]);
        fclose($rpipes[2]);
        $runExit = proc_close($run);
        @unlink($out);
        $this->assertSame(0, $runExit, trim($body."\n".$err));
        $this->assertSame($expected, $body);
    }
}
