<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT (#36155 Phase C). */
final class ChunkMethodManifestExportTest extends TestCase
{
    public function testManifestExportWritesLogicalToSymbolMap(): void
    {
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_manifest_export_'.uniqid('', true).'.php';
        $outBin = sys_get_temp_dir().'/phpc_manifest_export_'.uniqid('', true).'.bin';
        $manifest = sys_get_temp_dir().'/phpc_manifest_export_'.uniqid('', true).'.json';
        file_put_contents($src, <<<'PHP'
<?php
function manifest_probe(): int { return 7; }
echo manifest_probe();
PHP);
        $cmd = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($outBin)
            .' '.escapeshellarg($src);
        $env = 'PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT='.escapeshellarg($manifest);
        exec($env.' '.$cmd.' 2>&1', $output, $rc);
        @unlink($src);
        @unlink($outBin);
        $this->assertSame(0, $rc, implode("\n", $output));
        $this->assertFileExists($manifest);
        $payload = json_decode((string) file_get_contents($manifest), true);
        @unlink($manifest);
        $this->assertIsArray($payload);
        $this->assertGreaterThan(0, $payload['method_count'] ?? 0);
        $this->assertSame('manifest_probe', $payload['methods']['manifest_probe']['symbol'] ?? null);
    }
}
