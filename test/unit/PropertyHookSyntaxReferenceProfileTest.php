<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Property-hook syntax rejected on Zend 8.2 reference profile (#14062). */
final class PropertyHookSyntaxReferenceProfileTest extends TestCase
{
    private function skipWhenForwardProfile(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4 forward profile');
        }
    }

    public function testSupportsPropertyHooksFalseOnReferenceProfile(): void
    {
        $this->skipWhenForwardProfile();
        $this->assertFalse(CompilerVersion::supportsPropertyHooks());
    }

    public function testRejectorThrowsOnDefaultInitializerHook(): void
    {
        $this->skipWhenForwardProfile();
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_ARROW);
        PropertyHookSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hook_default_initializer.php'),
            'default_initializer.php'
        );
    }

    public function testRejectorThrowsOnHookBlock(): void
    {
        $this->skipWhenForwardProfile();
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_BRACE);
        PropertyHookSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hooks_reference_profile.php'),
            'reference_profile.php'
        );
    }

    public function testRuntimeRejectsDefaultInitializerHook(): void
    {
        $this->skipWhenForwardProfile();
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hook_default_initializer.php'),
                'default_initializer.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_ARROW, $e->getMessage());
        }
    }

    public function testRuntimeRejectsHookBlockSyntax(): void
    {
        $this->skipWhenForwardProfile();
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hooks_reference_profile.php'),
                'reference_profile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_BRACE, $e->getMessage());
        }
    }
}
