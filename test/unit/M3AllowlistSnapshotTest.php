<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** M3 compile-driver allowlist snapshot drift guard (#1905, #1768). */
final class M3AllowlistSnapshotTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testBootstrapM3AllowlistScriptsExist(): void
    {
        $this->assertFileExists(self::$root.'/script/bootstrap-m3-allowlist.php');
        $this->assertFileExists(self::$root.'/script/bootstrap-m3-allowlist-snapshot.php');
        $this->assertFileExists(self::$root.'/script/check-m3-allowlist-snapshot.php');
        $this->assertFileExists(self::$root.'/script/m3-allowlist-snapshot.txt');
    }

    public function testSnapshotMatchesJitAllowlistAndDenylist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $fromJit = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $fromSnapshot = bootstrap_m3_allowlist_read_snapshot(self::$root.'/script/m3-allowlist-snapshot.txt');

        $this->assertNotEmpty($fromJit['allow'], 'M3 allowlist must list Runtime spine symbols');
        $this->assertSame([], $fromJit['deny'], 'M3 denylist empty after #35009 (vestigial helloworld deny retired)');
        $this->assertSame($fromJit, $fromSnapshot);
    }

    public function testRuntimeConstructOnAllowlist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertContains('\\runtime::__construct', $lists['allow']);
    }

    public function testCompilerCompileFuncOnAllowlist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertContains('\\compiler::compilefunc', $lists['allow']);
    }

    /** JIT VarFetch / compile spine: operand slot lookup (#2848 follow-on). */
    public function testBlockSlotForOperandOnAllowlist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertContains('slotforoperand', $lists['allow']);
    }

    /** Issue #2867: last M3 deny fragment retired — void no-op lowering on compile spine. */
    public function testRuntimeDestructOnAllowlistNotDenylist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertContains('\\runtime::__destruct', $lists['allow']);
        $this->assertNotContains('\\runtime::__destruct', $lists['deny']);
    }

    /** Issue #35009: vestigial helloworld deny fragment retired (not on allowlist; never changed lowering). */
    public function testHelloworldCompileSmokeNotOnDenylist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertSame([], $lists['deny']);
        $this->assertNotContains('\\bootstrapaot\\helloworld_compile_smoke', $lists['deny']);
        $this->assertNotContains('\\bootstrapaot\\helloworld_compile_smoke', $lists['allow']);
    }

    public function testCompileSmokeM3EmitNotOnM3Allowlist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertNotContains('\\bootstrapaot\\compile_smoke_m3_emit', $lists['allow']);
        $this->assertNotContains('\\bootstrapaot\\helloworld_compile_smoke', $lists['deny']);
    }
}
