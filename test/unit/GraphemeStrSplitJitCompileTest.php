<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** AOT lint verify for grapheme_str_split() JIT (#6246 compile-time fold, #19964 runtime). */
final class GraphemeStrSplitJitCompileTest extends TestCase
{
    public function testGraphemeStrSplitAotLint(): void
    {
        $this->assertAotLintPasses('grapheme_str_split_literals.php');
    }

    public function testGraphemeStrSplitRuntimeAotLint(): void
    {
        $this->assertAotLintPasses('grapheme_str_split_runtime.php');
    }

    private function assertAotLintPasses(string $fixtureBasename): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/'.$fixtureBasename;
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = getenv();
        if (!\is_array($env)) {
            $env = [];
        }
        // PHP 8.4+ API — enable for AOT lint on 8.4.0-dev reference (#22340).
        $env['PHP_COMPILER_PROFILE'] = '8.4';
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.$fixtureBasename
        );
    }
}
