<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint verify for grapheme_str_contains() JIT/AOT lowering (#7128).
 *
 * php-src: ext/intl/grapheme/grapheme_string.c
 */
final class GraphemeStrContainsJitCompileTest extends TestCase
{
    /**
     * @dataProvider compileOnlyFixtureProvider
     */
    public function testGraphemeStrContainsAotLint(string $relativePath): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/'.$relativePath;
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.$relativePath
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function compileOnlyFixtureProvider(): array
    {
        return [
            ['test/fixtures/aot/compile-only/grapheme_str_contains_runtime.php'],
        ];
    }
}
