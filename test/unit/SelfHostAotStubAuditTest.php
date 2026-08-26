<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Self-host AOT stub audit snapshot drift guard (#8720, #1520). */
final class SelfHostAotStubAuditTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testAuditScriptsExist(): void
    {
        $this->assertFileExists(self::$root.'/script/selfhost-aot-stub-audit-lib.php');
        $this->assertFileExists(self::$root.'/script/audit-selfhost-aot-stubs.php');
        $this->assertFileExists(self::$root.'/script/check-selfhost-aot-stub-audit.php');
        $this->assertFileExists(self::$root.'/script/selfhost-aot-stub-audit-snapshot.json');
        $this->assertFileExists(self::$root.'/docs/selfhost-aot-stub-audit.md');
    }

    public function testSnapshotMatchesJitStubMetrics(): void
    {
        require_once self::$root.'/script/selfhost-aot-stub-audit-lib.php';

        $metrics = selfhost_aot_stub_collect_metrics(self::$root);
        $payload = selfhost_aot_stub_snapshot_payload($metrics);
        $snapshot = json_decode(
            (string) file_get_contents(self::$root.'/script/selfhost-aot-stub-audit-snapshot.json'),
            true
        );

        $this->assertIsArray($snapshot);
        $this->assertSame($payload, $snapshot);
        $this->assertGreaterThan(0, $metrics['compiler_skip_patterns']);
        $this->assertGreaterThan(0, $metrics['m3_allow']);
    }

    public function testRuntimeParseAndCompileOnM3RealLowering(): void
    {
        require_once self::$root.'/script/selfhost-aot-stub-audit-lib.php';

        $metrics = selfhost_aot_stub_collect_metrics(self::$root);
        $statusBySymbol = [];
        foreach ($metrics['spine_symbols'] as $row) {
            $statusBySymbol[$row['symbol']] = $row['status'];
        }
        $this->assertSame('m3_real', $statusBySymbol['PHPCompiler\\Runtime::parseAndCompile'] ?? null);
        $this->assertSame('m3_real', $statusBySymbol['PHPCompiler\\Runtime::standalone'] ?? null);
    }

    /** BootstrapAot fixtures are probe drivers, not compile-spine symbols (#35009). */
    public function testBootstrapFixturesNotOnCompileSpineAudit(): void
    {
        require_once self::$root.'/script/selfhost-aot-stub-audit-lib.php';

        $metrics = selfhost_aot_stub_collect_metrics(self::$root);
        $symbols = array_column($metrics['spine_symbols'], 'symbol');
        $this->assertNotContains('BootstrapAot\\helloworld_compile_smoke', $symbols);
        $this->assertNotContains('BootstrapAot\\compile_smoke_m3_emit', $symbols);
        $this->assertNotContains('BootstrapAot\\runtime_ctor_smoke', $symbols);
        $this->assertNotContains('BootstrapAot\\runtime_parse_compile_smoke', $symbols);
        $this->assertSame(0, $metrics['m3_deny']);
        $this->assertSame(0, $metrics['spine']['m3_deny']);
        $this->assertSame(0, $metrics['spine']['entry_stub'] + $metrics['spine']['compiler_stub'] + $metrics['spine']['m3_deny']);
    }

    public function testCompilerCompileFuncOnM3RealLowering(): void
    {
        require_once self::$root.'/script/selfhost-aot-stub-audit-lib.php';

        $metrics = selfhost_aot_stub_collect_metrics(self::$root);
        $statusBySymbol = [];
        foreach ($metrics['spine_symbols'] as $row) {
            $statusBySymbol[$row['symbol']] = $row['status'];
        }
        $this->assertSame('m3_real', $statusBySymbol['PHPCompiler\\Compiler::compileFunc'] ?? null);
        $this->assertSame('m3_real', $statusBySymbol['PHPCompiler\\Compiler::compile'] ?? null);
    }
}
