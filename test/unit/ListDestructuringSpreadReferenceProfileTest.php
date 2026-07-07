<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** List destructuring spread assignment reference profile gate (#17182). */
final class ListDestructuringSpreadReferenceProfileTest extends TestCase
{
    public function testSupportsListDestructuringSpreadAssignFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsListDestructuringSpreadAssign());
    }

    public function testRuntimeRejectsNumericListSpreadMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsListDestructuringSpreadAssign()) {
            $this->markTestSkipped('list spread assign enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_list_spread_reference_profile.php'),
                'maintainer_gap_list_spread_reference_profile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString('Spread operator is not supported in assignments', $e->getMessage());
        }
    }

    public function testRuntimeRejectsKeyedListSpreadMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsListDestructuringSpreadAssign()) {
            $this->markTestSkipped('list spread assign enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_keyed_list_spread_reference_profile.php'),
                'maintainer_gap_keyed_list_spread_reference_profile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString('Spread operator is not supported in assignments', $e->getMessage());
        }
    }

    public function testForwardProfileEnablesListSpreadAssign(): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (version_compare(CompilerVersion::VERSION, '8.4.0', '>=')) {
                $this->markTestSkipped('stable 8.4.0+ target always enables list spread assign');
            }
            $this->assertTrue(CompilerVersion::supportsListDestructuringSpreadAssign());
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_list_spread_reference_profile.php'),
                'maintainer_gap_list_spread_reference_profile.php'
            );
            $this->assertNotNull($block);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }
}
