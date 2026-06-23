<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmInfo;
use PHPUnit\Framework\TestCase;

/** ModuleRegistry extension introspection (#6372, #7190, #3433). */
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
        $this->assertTrue(VmInfo::extension_loaded('pcre'));
        $this->assertTrue(VmInfo::extension_loaded('zlib'));
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
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringInfo.php');
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/InfoJitHelper.php');
        $this->assertStringContainsString('InfoJitHelper', $bridge);
        $this->assertStringContainsString('ModuleRegistry::getLoadedExtensions', $helper);
        $this->assertStringContainsString('ModuleRegistry::getExtensionFunctions', $helper);
        $this->assertStringNotContainsString('ModuleRegistry::extensionFunctionMap', $bridge);
    }

    public function testGetExtensionFunctionsReturnsNullWhenNotLoaded(): void
    {
        ModuleRegistry::reset();
        $this->assertNull(ModuleRegistry::getExtensionFunctions('hash'));
    }

    public function testRegisterModulePartitionsJsonAndDateFromStandard(): void
    {
        ModuleRegistry::reset();
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $runtime->load(new ext\standard\Module());
        $runtime->load(new ext\hash\Module());

        $hash = ModuleRegistry::getExtensionFunctions('hash');
        $this->assertIsArray($hash);
        $this->assertContains('hash_init', $hash);

        $json = ModuleRegistry::getExtensionFunctions('json');
        $this->assertIsArray($json);
        $this->assertContains('json_encode', $json);
        $this->assertNotContains('json_encode', ModuleRegistry::getExtensionFunctions('standard') ?? []);

        $date = ModuleRegistry::getExtensionFunctions('date');
        $this->assertIsArray($date);
        $this->assertContains('date', $date);
        $this->assertNotContains('date', ModuleRegistry::getExtensionFunctions('standard') ?? []);

        $pcre = ModuleRegistry::getExtensionFunctions('pcre');
        $this->assertIsArray($pcre);
        $this->assertContains('preg_match', $pcre);
        $this->assertNotContains('preg_match', ModuleRegistry::getExtensionFunctions('standard') ?? []);

        $zlib = ModuleRegistry::getExtensionFunctions('zlib');
        $this->assertIsArray($zlib);
        $this->assertContains('gzdeflate', $zlib);
        $this->assertNotContains('gzdeflate', ModuleRegistry::getExtensionFunctions('standard') ?? []);

        $this->assertNull(ModuleRegistry::getExtensionFunctions('missing_ext'));

        unset($runtime);
    }
}
