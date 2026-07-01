<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Property-hook syntax rejected on Zend 8.2 reference profile (#14062); forward profile #14432. */
final class PropertyHookSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsPropertyHooksTrueOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsPropertyHooks());
    }

    public function testRejectorThrowsOnDefaultInitializerHook(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_ARROW);
        PropertyHookSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hook_default_initializer.php'),
            'default_initializer.php'
        );
    }

    public function testRejectorThrowsOnHookBlock(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_BRACE);
        PropertyHookSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hooks_reference_profile.php'),
            'reference_profile.php'
        );
    }

    public function testRuntimeRejectsDefaultInitializerHook(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
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
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
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
