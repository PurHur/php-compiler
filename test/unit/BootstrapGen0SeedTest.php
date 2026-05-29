<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapGen0SeedTest extends TestCase
{
    public function testGen0SeedManifestAndDriverPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = $root.'/prelinked/bootstrap-gen0/manifest.json';
        $driver = $root.'/prelinked/bootstrap-gen0/bin-compile-aot';
        $this->assertFileExists($manifest);
        $this->assertFileExists($driver);
        $this->assertFileIsExecutable($driver);
        $data = json_decode((string) file_get_contents($manifest), true);
        $this->assertIsArray($data);
        $expectBytes = (int) ($data['artifacts']['bin-compile-aot']['bytes'] ?? 0);
        $expectSha = (string) ($data['artifacts']['bin-compile-aot']['sha256'] ?? '');
        $this->assertGreaterThan(300000, $expectBytes);
        $this->assertSame($expectBytes, (int) filesize($driver));
        $this->assertSame($expectSha, hash_file('sha256', $driver));
    }

    public function testBootstrapGen0SeedScriptDocumentsM5NoZend(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/script/bootstrap-selfhost-link.sh');
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND', $script);
        $this->assertStringContainsString('bootstrap-gen0-seed.sh', $script);
    }
}
