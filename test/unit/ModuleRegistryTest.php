<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmInfo;
use PHPUnit\Framework\TestCase;

/** ModuleRegistry extension introspection (#6372, #7190). */
final class ModuleRegistryTest extends TestCase
{
    public function testRuntimeRegistersInTreeExtensions(): void
    {
        $runtime = new Runtime(Runtime::MODE_NORMAL);

        $this->assertTrue(VmInfo::extension_loaded('standard'));
        $this->assertTrue(VmInfo::extension_loaded('types'));
        $this->assertTrue(VmInfo::extension_loaded('hash'));
        $this->assertTrue(VmInfo::extension_loaded('zip'));
        $this->assertTrue(VmInfo::extension_loaded('spl'));
        $this->assertTrue(VmInfo::extension_loaded('json'));
        $this->assertTrue(VmInfo::extension_loaded('date'));
        $this->assertFalse(VmInfo::extension_loaded('nonexistent_xyz'));

        $this->assertNotFalse(VmInfo::phpversion('zip'));
        $this->assertNotFalse(VmInfo::phpversion('spl'));
        $this->assertFalse(VmInfo::phpversion('nonexistent_xyz'));

        $loaded = ModuleRegistry::getLoadedExtensions();
        $this->assertContains('zip', $loaded);
        $this->assertContains('spl', $loaded);
        $this->assertContains('hash', $loaded);

        unset($runtime);
    }

    public function testStringInfoJitUsesModuleRegistry(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringInfo.php');
        $this->assertStringContainsString('ModuleRegistry::getLoadedExtensions', $source);
        $this->assertStringNotContainsString('LOADED_EXTENSIONS', $source);
    }
}
