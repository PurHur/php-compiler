<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/2254
 */
final class BootstrapInventoryTriageTest extends TestCase
{
    public function testTriageScriptRuns(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-inventory-triage.php';
        $this->assertFileExists($script);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --top 5 2>&1';
        $out = shell_exec($cmd);
        $this->assertIsString($out);
        if (str_contains($out, 'bootstrap-inventory-triage: lint --bootstrap-inventory failed')) {
            $this->markTestSkipped('live lint --bootstrap-inventory not runnable in this PHP build');
        }
        $this->assertStringContainsString('bootstrap-inventory-triage:', $out);
        $this->assertStringContainsString('| Rank | CFG gap |', $out);
    }

    public function testTriageJsonPayload(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-inventory-triage.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --json --top 3 2>&1';
        $raw = shell_exec($cmd);
        $this->assertIsString($raw);
        if (str_contains($raw, 'bootstrap-inventory-triage: lint --bootstrap-inventory failed')) {
            $this->markTestSkipped('live lint --bootstrap-inventory not runnable in this PHP build');
        }
        $json = preg_replace('/^[^\{]*/', '', $raw) ?? $raw;
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('rows', $decoded);
        $this->assertArrayHasKey('scanned', $decoded);
        $this->assertGreaterThan(100, $decoded['scanned']);
    }

    public function testTriageRowsFromFixtureReport(): void
    {
        require dirname(__DIR__, 2).'/script/bootstrap-inventory-lint-sync-lib.php';
        $report = [
            'files' => [
                'lib/A.php' => ['Unknown Stmt Type: Stmt\\Foo', 'Terminal_StaticVar'],
                'lib/B.php' => ['Unknown Stmt Type: Stmt\\Foo'],
                'lib/C.php' => ['Unsupported unset target: '],
            ],
        ];
        $rows = bootstrap_inventory_lint_triage_rows($report, 10);
        $this->assertCount(3, $rows);
        $this->assertSame('Unknown Stmt Type: Stmt\\Foo', $rows[0]['message']);
        $this->assertSame(2, $rows[0]['file_count']);
        $this->assertSame(2276, $rows[0]['issue']);
        $this->assertSame(2273, $rows[2]['issue']);
    }
}
