<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmFilter regexp validation without host \\preg_match() delegation (#8234, #6028 phase 2). */
final class VmFilterRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function test_vm_filter_has_no_host_preg_match_delegation(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/filter/VmFilter.php');
        $this->assertStringNotContainsString('\\preg_match(', $source);
        $this->assertStringContainsString('VmPregNative::pregMatch', $source);
    }
}
