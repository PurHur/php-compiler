<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Stdlib JIT deferred drift guard (#2465). */
final class StdlibJitDeferredSyncTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root.'/script/stdlib-jit-deferred-sync-lib.php';
    }

    public function testStdlibJitDeferredSyncArtifactsExist(): void
    {
        $this->assertFileExists(self::$root.'/script/check-stdlib-jit-deferred-sync.php');
        $this->assertFileExists(self::$root.'/script/stdlib-jit-deferred-lib.php');
        $this->assertFileExists(self::$root.'/docs/stdlib-jit-audit.md');
    }

    public function testStdlibJitDeferredSyncPassesOnMaster(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/script/check-stdlib-jit-deferred-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-stdlib-jit-deferred-sync: OK', implode("\n", $out));
    }

    public function testAuditDeferredParser(): void
    {
        $audit = <<<'MD'
| Deferred (VM-only) | 1 |

## Deferred (VM-only)

- `spl_autoload_register` (autoload.) — `ext/standard/spl_autoload_register.php`

## Present (sorted)
MD;
        $parsed = stdlib_jit_deferred_parse_audit_deferred($audit);
        $this->assertSame(['spl_autoload_register'], $parsed);
        $this->assertSame(1, stdlib_jit_deferred_parse_audit_metric_count($audit, 'Deferred (VM-only)'));
    }

    public function testCapabilitiesDeferralNoteRequiredWhenJitYes(): void
    {
        $row = '| `spl_autoload_register` | yes | yes | yes | standard | JIT PHPT |';
        $this->assertFalse(stdlib_jit_deferred_capabilities_notes_ok($row, 2441));
        $rowOk = '| `spl_autoload_register` | yes | yes | yes | standard | compile-time JIT deferred (#2441); JIT PHPT |';
        $this->assertTrue(stdlib_jit_deferred_capabilities_notes_ok($rowOk, 2441));
    }
}
