<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #11902 */
final class BuiltinAttributePhantomTest extends TestCase
{
    public function testForwardCompatAttributeClassesNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesOverrideAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesDeprecatedAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesNoDiscardAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesDelayedTargetValidationAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesCompileTimeAttributeClass());
    }

    public function testVmMaintainerReproGreen(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile(
            (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_builtin_attribute_phantom.php'),
            'maintainer_gap_builtin_attribute_phantom.php'
        ));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
