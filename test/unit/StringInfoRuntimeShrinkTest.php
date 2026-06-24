<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\InfoJitHelper;
use PHPCompiler\ext\standard\VmInfo;
use PHPUnit\Framework\TestCase;

/** StringInfo routes info builtins through InfoJitHelper PHP (#9148). */
final class StringInfoRuntimeShrinkTest extends TestCase
{
    public function testStringInfoUsesJitHelperBridgeNotLlvmLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringInfo.php');
        $this->assertStringContainsString('InfoJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('ensureLibc', $source);
        $this->assertStringNotContainsString('UTSNAME_SIZE', $source);
        $this->assertStringNotContainsString('stringEqualsIgnoreCase', $source);
        $this->assertStringNotContainsString('ModuleRegistry::getLoadedExtensions', $source);
        $this->assertLessThan(620, \substr_count($source, "\n") + 1);
    }

    public function testInfoJitHelperDelegatesToVmInfo(): void
    {
        $this->assertSame(VmInfo::php_sapi_name(), InfoJitHelper::php_sapi_name());
        $this->assertSame(VmInfo::zend_version(), InfoJitHelper::zend_version());
        $this->assertSame(VmInfo::phpversion(), InfoJitHelper::phpversion(null));
        $this->assertSame(VmInfo::phpversion('pcre'), InfoJitHelper::phpversion('pcre'));
        $this->assertSame(
            VmInfo::extension_loaded('standard'),
            InfoJitHelper::extension_loaded('standard')
        );
        $this->assertSame(0, InfoJitHelper::prepareGetExtensionFuncs(''));
        $this->assertGreaterThan(0, InfoJitHelper::countLoadedExtensions(0));
    }
}
