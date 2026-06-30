<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Property-hook syntax on 8.4 forward profile (#13904). */
final class PropertyHookSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsPropertyHooksTrueOn84ForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsPropertyHooks());
    }

    public function testRejectorThrowsOnDefaultInitializerHook(): void
    {
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_ARROW);
        PropertyHookSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hook_default_initializer.php'),
            'default_initializer.php'
        );
    }

    public function testRejectorAcceptsHookBlockOn84ForwardProfile(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hook_get_basic.php');
        $this->assertSame($code, PropertyHookSyntaxRejector::reject($code, 'get_basic.php'));
    }

    public function testRuntimeRejectsDefaultInitializerHook(): void
    {
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
