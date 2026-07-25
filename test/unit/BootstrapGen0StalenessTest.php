<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Git-derived gen-0 seed age (#22642).
 *
 * The manifest stamp is a local claim and can be written without rebuilding. This check reads
 * git history instead, so a seed whose bytes predate lowering-source commits reports stale
 * regardless of what the stamp says.
 */
final class BootstrapGen0StalenessTest extends TestCase
{
    private static string $script;

    public static function setUpBeforeClass(): void
    {
        self::$script = \dirname(__DIR__, 2).'/script/bootstrap-gen0-staleness.php';
    }

    public function testScriptExists(): void
    {
        $this->assertFileExists(self::$script);
    }

    public function testEmitsWellFormedJsonForThisCheckout(): void
    {
        $out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$script).' --json 2>/dev/null');
        $this->assertIsString($out);

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'staleness check must emit an object');
        $this->assertArrayHasKey('status', $decoded);
        $this->assertContains($decoded['status'], ['fresh', 'stale', 'unknown']);
        $this->assertArrayHasKey('message', $decoded);
    }

    /** A seed reported stale must say why, in numbers a reader can check against git. */
    public function testStaleVerdictCarriesItsEvidence(): void
    {
        $out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$script).' --json 2>/dev/null');
        $decoded = json_decode((string) $out, true);
        $this->assertIsArray($decoded);

        if ('stale' !== $decoded['status']) {
            $this->assertSame('fresh', $decoded['status'] === 'unknown' ? 'fresh' : $decoded['status']);
            $this->markTestSkipped('gen-0 seed is not stale in this checkout');
        }

        $this->assertArrayHasKey('driver_last_built', $decoded);
        $this->assertArrayHasKey('commit', $decoded['driver_last_built']);
        $this->assertArrayHasKey('lowering_commits_since', $decoded);
        $this->assertGreaterThan(
            0,
            $decoded['lowering_commits_since'],
            'a stale verdict means lowering sources moved after the seed was built'
        );
        $this->assertArrayHasKey('manifest_commits_since', $decoded);
        $this->assertArrayHasKey('manifest_provenance', $decoded);
    }

    /** Exit code carries the verdict so shell callers do not have to parse JSON. */
    public function testExitCodeMatchesVerdict(): void
    {
        $out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$script).' --json 2>/dev/null');
        $decoded = json_decode((string) $out, true);
        $this->assertIsArray($decoded);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$script).' --json 2>/dev/null', $lines, $code);

        $expected = ['fresh' => 0, 'stale' => 1, 'unknown' => 2];
        $this->assertSame($expected[$decoded['status']], $code);
    }
}
