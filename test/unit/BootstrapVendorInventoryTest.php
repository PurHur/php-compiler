<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap vendor inventory (M5 prelink path, issue #2030).
 */
final class BootstrapVendorInventoryTest extends TestCase
{
    public function testVendorInventoryScriptRuns(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-vendor-inventory.php').' --json 2>/dev/null';
        $json = shell_exec($cmd);
        $this->assertIsString($json);
        $report = json_decode($json, true);
        $this->assertIsArray($report);
        $this->assertGreaterThan(100, $report['totals']['php_files']);
        $this->assertArrayHasKey('nikic/php-parser', $report['packages']);
        $this->assertArrayHasKey('ircmaxell/php-llvm', $report['packages']);
    }

    public function testVendorInventoryDocIsFresh(): void
    {
        $root = dirname(__DIR__, 2);
        $doc = $root.'/docs/bootstrap-vendor-inventory.md';
        $this->assertFileExists($doc);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-vendor-inventory.php').' --check 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
