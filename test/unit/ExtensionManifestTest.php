<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Per-extension manifests (ext/<name>/ext.json) are the registry source of truth (#36204).
 */
final class ExtensionManifestTest extends TestCase
{
    public function testEveryRegistryExtensionHasManifest(): void
    {
        $root = dirname(__DIR__, 2);
        $names = [];
        foreach (ExtensionRegistry::defaultModules() as $module) {
            $class = \get_class($module);
            self::assertSame(1, preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', $class, $m));
            $names[] = $m[1];
            $path = $root.'/ext/'.$m[1].'/ext.json';
            self::assertFileExists($path, 'missing manifest for '.$m[1]);
            $data = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($data);
            self::assertSame($m[1], $data['name'] ?? null);
            self::assertIsInt($data['load_order'] ?? null);
            self::assertIsArray($data['depends'] ?? null);
            self::assertIsArray($data['backends'] ?? null);
            foreach (['vm', 'jit', 'aot'] as $backend) {
                self::assertArrayHasKey($backend, $data['backends']);
                self::assertIsBool($data['backends'][$backend]);
            }
            self::assertTrue($data['backends']['vm'], $m[1].' must support VM');
        }

        self::assertCount(84, $names, 'expected 84 extensions in registry + manifests');
        self::assertSame(range(0, 83), array_column(
            array_map(
                static fn (string $n): array => json_decode(
                    (string) file_get_contents($root.'/ext/'.$n.'/ext.json'),
                    true
                ),
                $names
            ),
            'load_order'
        ), 'load_order must be dense 0..n-1 matching registry order');
    }

    public function testDocsExtensionsTableExists(): void
    {
        $path = dirname(__DIR__, 2).'/docs/extensions.md';
        self::assertFileExists($path);
        $body = (string) file_get_contents($path);
        self::assertStringContainsString('# Extensions', $body);
        self::assertStringContainsString('| `standard` |', $body);
        self::assertStringContainsString('generate-extension-manifests.php', $body);
    }
}
