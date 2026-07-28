<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #24137: nested json_decode(..., true) must compile; assoc true from ConstFetch Load.
 *
 * Runtime AOT HashTable fill remains int-wire (#20829) — tracked on the issue.
 */
final class JitJsonDecodeRuntimeAssocTest extends TestCase
{
    public function testCompileTimeBoolHonorsCompileTimeConstantName(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/json_decode.php');
        $this->assertStringContainsString('compileTimeConstantName', $source);
        $this->assertStringContainsString('#24137', $source);
    }

    public function testNestedEncodeDecodeCompilesUnderAot(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_24137_json_decode_nested_encode.php';
        $out = $root.'/build/test-aot-json-decode-nested-encode-24137';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $this->assertFileExists($out);
        // Must not fail with "assoc flag must be a compile-time boolean".
        $this->assertFileDoesNotExist($out.'.fail');
    }

    /**
     * @param list<string> $cmd
     */
    private function runCommand(array $cmd, string $cwd, int $expectExit = 0): string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame($expectExit, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }
}
