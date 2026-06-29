<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** parse_url() — negative $component returns full assoc array (#10645). */
final class ParseUrlNegativeComponentTest extends TestCase
{
    public function testNegativeOneReturnsFullArrayOnVm(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_parse_url_negative_component.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertStringContainsString("'scheme' => 'http'", (string) $out);
        self::assertStringContainsString("'host' => 'example.com'", (string) $out);
        self::assertStringContainsString("'path' => '/path'", (string) $out);
    }

    public function testNegativeComponentAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        self::assertNotFalse($bin);
        $target = $root.'/test/repro/maintainer_gap_parse_url_negative_component.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([PHP_BINARY, $bin, '-l', $target], $descriptorSpec, $pipes, $root);
        self::assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for parse_url -1 probe (#10645)'
        );
    }
}
