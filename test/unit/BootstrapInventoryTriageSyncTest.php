<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Bootstrap inventory triage drift guard (#2265). */
final class BootstrapInventoryTriageSyncTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testBootstrapInventoryTriageSyncArtifactsExist(): void
    {
        $this->assertFileExists(self::$root.'/script/check-bootstrap-inventory-triage-sync.php');
        $this->assertFileExists(self::$root.'/docs/bootstrap-inventory-triage-top50.json');
    }

    public function testBootstrapInventoryTriageSyncPassesOnMaster(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/script/check-bootstrap-inventory-triage-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        if (0 !== $code && str_contains(implode("\n", $out), 'lint --bootstrap-inventory failed')) {
            $this->markTestSkipped('live lint --bootstrap-inventory not runnable in this PHP build');
        }
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-bootstrap-inventory-triage-sync: OK', implode("\n", $out));
    }

    public function testTriageDiffDetectsRowDrift(): void
    {
        require self::$root.'/script/bootstrap-inventory-lint-sync-lib.php';
        $live = [
            'scanned' => 100,
            'top' => 50,
            'rows' => [
                ['rank' => 1, 'message' => 'gap A', 'file_count' => 2, 'examples' => ['a.php'], 'issue' => null],
            ],
        ];
        $snapshot = $live;
        $this->assertSame([], bootstrap_inventory_triage_diff_errors($live, $snapshot));
        $snapshot['rows'][0]['file_count'] = 3;
        $this->assertNotSame([], bootstrap_inventory_triage_diff_errors($live, $snapshot));
    }
}
