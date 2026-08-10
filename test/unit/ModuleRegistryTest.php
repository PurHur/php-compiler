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
        // Compiler-internal types module is not a php-src extension (#28155).
        $this->assertFalse(VmInfo::extension_loaded('types'));
        $this->assertTrue(VmInfo::extension_loaded('hash'));
        $this->assertSame(
            \PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension(),
            VmInfo::extension_loaded('zip')
        );
        $this->assertTrue(VmInfo::extension_loaded('spl'));
        $this->assertTrue(VmInfo::extension_loaded('json'));
        $this->assertTrue(VmInfo::extension_loaded('date'));
        $this->assertTrue(VmInfo::extension_loaded('pcre'));
        $this->assertTrue(VmInfo::extension_loaded('zlib'));
        $this->assertTrue(VmInfo::extension_loaded('openssl'));
        $this->assertFalse(VmInfo::extension_loaded('curl'));
        $this->assertSame(
            \PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExtensionLoaded(),
            VmInfo::extension_loaded('sqlite3')
        );
        $this->assertFalse(VmInfo::extension_loaded('nonexistent_xyz'));

        if (\PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
            $this->assertNotFalse(VmInfo::phpversion('zip'));
        }
        $this->assertNotFalse(VmInfo::phpversion('spl'));
        $this->assertFalse(VmInfo::phpversion('nonexistent_xyz'));

        $core = VmInfo::phpversion();
        $this->assertSame($core, VmInfo::phpversion('pcre'));
        $this->assertSame('10.44', ModuleRegistry::getLibraryExtensionVersion('pcre'));

        $loaded = ModuleRegistry::getLoadedExtensions();
        if (\PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
            $this->assertContains('zip', $loaded);
        } else {
            $this->assertNotContains('zip', $loaded);
        }
        $this->assertContains('SPL', $loaded);
        $this->assertContains('hash', $loaded);
        $this->assertContains('Core', $loaded);
        $this->assertNotContains('types', $loaded);
        $this->assertSame('Core', ModuleRegistry::displayNameForExtension('core'));
        $this->assertSame('Zend OPcache', ModuleRegistry::displayNameForExtension('zend opcache'));

        unset($runtime);
    }

    public function testStringInfoJitUsesModuleRegistry(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringInfo.php');
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/InfoJitHelper.php');
        $this->assertStringContainsString('InfoJitHelper', $bridge);
        $this->assertStringContainsString('ModuleRegistry::getLoadedExtensions', $helper);
        // get_extension_funcs path is VmInfo::get_extension_funcs → ModuleRegistry (#13803).
        $this->assertStringContainsString('VmInfo::get_extension_funcs', $helper);
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

        $advertised = ModuleRegistry::advertisedInternalFunctionNames();
        $this->assertContains('ctype_alnum', $advertised);
        $this->assertContains('filter_var', $advertised);
        $this->assertContains('strlen', $advertised);

        unset($runtime);
    }

    public function testReflectionOwningExtensionRoutesBundledModules(): void
    {
        $this->assertSame('core', ModuleRegistry::reflectionOwningExtension('func_num_args'));
        $this->assertSame('standard', ModuleRegistry::reflectionOwningExtension('is_array'));
        $this->assertSame('standard', ModuleRegistry::reflectionOwningExtension('strptime'));
        $this->assertSame('hash', ModuleRegistry::reflectionOwningExtension('hash_hmac'));
        $this->assertSame('spl', ModuleRegistry::reflectionOwningExtension('spl_autoload'));
        $this->assertSame('random', ModuleRegistry::reflectionOwningExtension('mt_rand'));
        $this->assertSame('curl', ModuleRegistry::reflectionOwningExtension('curl_version'));
        $this->assertSame('standard', ModuleRegistry::reflectionOwningExtension('socket_get_status'));
    }
}
