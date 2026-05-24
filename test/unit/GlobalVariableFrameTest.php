<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class GlobalVariableFrameTest extends TestCase
{
  private static string $root;

  public static function setUpBeforeClass(): void
  {
    self::$root = dirname(__DIR__, 2);
  }

  public function testGlobalVarLinkRunsUnderVm(): void
  {
    $script = self::$root.'/test/bootstrap-aot/global_var_link.php';
    $cmd = implode(' ', array_map('escapeshellarg', ['php', self::$root.'/bin/vm.php', $script])).' 2>&1';
    exec($cmd, $lines, $exitCode);

    $this->assertSame(0, $exitCode, implode("\n", $lines));
    $this->assertStringContainsString('2', implode("\n", $lines));
  }

  public function testGlobalReadWriteComplianceCaseExists(): void
  {
    $this->assertFileExists(self::$root.'/test/compliance/cases/language/global_read_write.phpt');
    $this->assertFileExists(self::$root.'/test/compliance/cases/language/global_read_write_jit.phpt');
  }

  public function testBlockDeclaresGlobalNameHelper(): void
  {
    $source = (string) file_get_contents(self::$root.'/lib/Block.php');
    $this->assertStringContainsString('declaresGlobalName', $source);
    $this->assertStringContainsString('TYPE_DECLARE_GLOBAL', $source);
  }
}
