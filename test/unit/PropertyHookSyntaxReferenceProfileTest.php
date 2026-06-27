<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Property-hook syntax reference profile gate (#12574). */
final class PropertyHookSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsPropertyHooksFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPropertyHooks());
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

    public function testRejectorThrowsOnHookBlockAfterProperty(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_BRACE);
        PropertyHookSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hook_isset_empty.php'),
            'isset_empty.php'
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
}
