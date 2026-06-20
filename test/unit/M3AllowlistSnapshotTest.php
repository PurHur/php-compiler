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
        $this->assertNotEmpty($fromJit['deny'], 'M3 denylist must list LLVM 9 crash fragments');
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

    public function testHelloworldCompileSmokeOnDenylist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertContains('\\bootstrapaot\\helloworld_compile_smoke', $lists['deny']);
    }

    public function testCompileSmokeM3EmitNotOnM3Allowlist(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_from_jit(self::$root.'/lib/JIT.php');
        $this->assertNotContains('\\bootstrapaot\\compile_smoke_m3_emit', $lists['allow']);
        $this->assertContains('\\bootstrapaot\\helloworld_compile_smoke', $lists['deny']);
    }
}
