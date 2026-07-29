<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Property-hook syntax rejected on Zend 8.2 reference profile (#14062, #18019); forward profile #14432. */
final class PropertyHookSyntaxReferenceProfileTest extends TestCase
{
    private ?string $savedProfile = null;

    protected function setUp(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->savedProfile = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_PROFILE=8.2');
    }

    protected function tearDown(): void
    {
        if (null === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
        }
    }

    public function testSupportsPropertyHooksFalseWhenProfile82(): void
    {
        $this->assertFalse(CompilerVersion::supportsPropertyHooks());
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

    public function testRejectorThrowsOnHookBlock(): void
    {
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_BRACE);
        PropertyHookSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hooks_reference_profile_parse.php'),
            'reference_profile.php'
        );
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

    /** Default profile accepts property hooks now that VERSION_ID >= 80400 (#24754). */
    public function testRuntimeAcceptsHookBlockOnDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hooks_basic.php'),
                'maintainer_gap_property_hooks_basic.php'
            );
            $this->addToAssertionCount(1);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRuntimeRejectsHookBlockSyntax(): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_hooks_reference_profile_parse.php'),
                'reference_profile.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_BRACE, $e->getMessage());
        }
    }
}
