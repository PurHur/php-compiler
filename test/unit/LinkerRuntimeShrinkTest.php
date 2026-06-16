<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** lib/AOT/Linker.php must not call host shell_exec — use phpc_run_command (#8750, re-#2779). */
final class LinkerRuntimeShrinkTest extends TestCase
{
    public function testLinkerDoesNotCallHostShellExec(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\shell_exec\\(/', $source);
    }

    public function testLinkerDoesNotEmbedBundledLiblzf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('ensureBundledLiblzf', $source);
        $this->assertStringNotContainsString('liblzf.a', $source);
        $this->assertStringContainsString('runCaptured', $source);
        $this->assertStringContainsString('phpc_run_command', $source);
    }

    public function testRunCapturedDelegatesToPhpcRunCommand(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('\\phpc_run_command($command)', $source);
    }
}
