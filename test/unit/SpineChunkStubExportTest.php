<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** spine-chunk-stub-export.php (#36147). */
final class SpineChunkStubExportTest extends TestCase
{
    public function testExportsStubListFromLogLine(): void
    {
        $root = dirname(__DIR__, 2);
        $log = $root.'/build/micro/chunk/_stub_export_test.log';
        $out = $root.'/build/micro/chunk/_stub_export_test.stubs.json';
        @mkdir(dirname($log), 0775, true);
        file_put_contents($log, "phpc: external method stubs — 3 method call(s) lowered to a silent null — class not in this module (#579): object::int, count, phpcompiler\\ext\\standard\\strpos\n");

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/spine-chunk-stub-export.php')
            .' --write '.escapeshellarg($out).' '.escapeshellarg($log).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
        $this->assertFileExists($out);
        $payload = json_decode((string) file_get_contents($out), true);
        $this->assertIsArray($payload);
        $this->assertSame(3, $payload['stub_count']);
        $this->assertContains('object::int', $payload['stubs']);
        $this->assertContains('count', $payload['stubs']);
    }

    public function testReadsFullStubListFromCompilerJson(): void
    {
        $root = dirname(__DIR__, 2);
        $in = $root.'/build/micro/chunk/_stub_export_compiler.json';
        $out = $root.'/build/micro/chunk/_stub_export_compiler_out.json';
        @mkdir(dirname($in), 0775, true);
        $names = [];
        for ($i = 0; $i < 50; ++$i) {
            $names[] = 'stub_'.$i;
        }
        file_put_contents($in, json_encode(['stub_count' => 50, 'stubs' => $names], JSON_PRETTY_PRINT)."\n");

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/spine-chunk-stub-export.php')
            .' --write '.escapeshellarg($out).' '.escapeshellarg($in).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
        $payload = json_decode((string) file_get_contents($out), true);
        $this->assertSame(50, $payload['stub_count']);
    }
}
