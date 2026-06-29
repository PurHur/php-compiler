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
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('ensureLibc', $source);
        $this->assertStringNotContainsString('UTSNAME_SIZE', $source);
        $this->assertStringNotContainsString('stringEqualsIgnoreCase', $source);
        $this->assertStringNotContainsString('__hashtable__setStringAt', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testInfoJitHelperDelegatesToVmInfo(): void
    {
        $this->assertSame(VmInfo::php_sapi_name(), InfoJitHelper::php_sapi_name());
        $this->assertSame(VmInfo::zend_version(), InfoJitHelper::zend_version());
        $this->assertSame(VmInfo::phpversion(), InfoJitHelper::phpversion(null));
        $this->assertSame(VmInfo::phpversion('Core'), InfoJitHelper::phpversion('Core'));
        $this->assertSame(
            VmInfo::extension_loaded('standard'),
            InfoJitHelper::extension_loaded('standard')
        );
        $this->assertNull(InfoJitHelper::getExtensionFuncsArgv(''));
        $this->assertSame(
            InfoJitHelper::countLoadedExtensions(0),
            \count(InfoJitHelper::getLoadedExtensionsArgv(0)->exportKeyValuePairs())
        );
    }
}
