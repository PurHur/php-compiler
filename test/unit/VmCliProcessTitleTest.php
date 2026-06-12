<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** cli_get/set_process_title — PHP-in-PHP VM path (#5155). */
final class VmCliProcessTitleTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testBuiltinSourcesUseVmCliAndBuiltinExecute(): void
    {
        $set = (string) file_get_contents($this->repoRoot.'/ext/standard/cli_set_process_title.php');
        $get = (string) file_get_contents($this->repoRoot.'/ext/standard/cli_get_process_title.php');
        $this->assertStringContainsString('VmCli::setProcessTitle', $set);
        $this->assertStringContainsString('BuiltinExecute::writeReturn', $set);
        $this->assertStringContainsString('VmCli::getProcessTitle', $get);
        $this->assertStringContainsString('BuiltinExecute::writeReturn', $get);
    }

    public function testModuleRegistersCliProcessTitleBuiltins(): void
    {
        $module = (string) file_get_contents($this->repoRoot.'/ext/standard/Module.php');
        $this->assertStringContainsString('new cli_get_process_title()', $module);
        $this->assertStringContainsString('new cli_set_process_title()', $module);
    }

    public function testCompliancePhptPresent(): void
    {
        $this->assertFileExists($this->repoRoot.'/test/compliance/cases/stdlib/cli_process_title.phpt');
    }
}
