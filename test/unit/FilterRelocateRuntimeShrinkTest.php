<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ext/filter relocation — no VmFilter under ext/standard (#6028). */
final class FilterRelocateRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testFilterSemanticsLiveUnderExtFilterOnly(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/VmFilter.php');
        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/JitFilter.php');
        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/filter_var.php');
        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/filter_input.php');
        $this->assertFileExists($this->repoRoot.'/ext/filter/VmFilter.php');
        $this->assertFileExists($this->repoRoot.'/ext/filter/JitFilter.php');
        $this->assertFileExists($this->repoRoot.'/ext/filter/filter_var.php');
        $this->assertFileExists($this->repoRoot.'/ext/filter/filter_input.php');
    }

    public function testFilterModuleRegistersAllFilterBuiltinsOnce(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/filter/Module.php');
        $this->assertStringContainsString('new filter_var()', $source);
        $this->assertStringContainsString('new filter_input()', $source);
        $this->assertStringContainsString('new filter_list()', $source);
        $this->assertStringContainsString('new filter_id()', $source);
        $standard = (string) file_get_contents($this->repoRoot.'/ext/standard/Module.php');
        $this->assertStringNotContainsString('filter_var', $standard);
        $this->assertStringNotContainsString('filter_input', $standard);
    }

    public function testFilterIdMatchesZendForSupportedFilters(): void
    {
        $this->assertSame(274, \PHPCompiler\ext\filter\FilterConstants::idForName('validate_email'));
        $this->assertSame(272, \PHPCompiler\ext\filter\FilterConstants::idForName('validate_regexp'));
        $this->assertSame(257, \PHPCompiler\ext\filter\FilterConstants::idForName('int'));
        $this->assertContains('validate_email', \PHPCompiler\ext\filter\FilterConstants::supportedFilterNames());
        $this->assertContains('validate_regexp', \PHPCompiler\ext\filter\FilterConstants::supportedFilterNames());
    }
}
