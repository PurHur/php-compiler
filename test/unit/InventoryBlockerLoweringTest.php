<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class InventoryBlockerLoweringTest extends TestCase {
    public function testNullsafeMethodCallLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/nullsafe_method_call.php')); }
    public function testAssignRefLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/assign_ref_alias.php')); }
    public function testGlobalVarLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/global_var_link.php')); }
    public function testShellExecLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/shell_exec_echo.php')); }
    public function testShiftRightLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/shift_right.php')); }
    public function testTryCatchLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/try_catch.php')); }
    private function lint(string $rel): int {
        $root = dirname(__DIR__, 2);
        exec(sprintf('%s %s/bin/compile.php -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($root), escapeshellarg($root.'/'.$rel)), $out, $code);
        if (0 !== $code) { self::fail(implode("\n", $out)); }
        return $code;
    }
}
