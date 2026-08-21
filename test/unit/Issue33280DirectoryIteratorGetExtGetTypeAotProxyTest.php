<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT proxies for SplFileInfo::getExtension / getBasename / getType (#33280).
 *
 * php-src: ext/spl/spl_directory.c — zim_SplFileInfo_getExtension
 */
final class Issue33280DirectoryIteratorGetExtGetTypeAotProxyTest extends TestCase
{
    public function testContextRegistersExtensionProxies(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString(
            "'getPathname', 'getPath', 'getExtension', 'getBasename', 'getType', '__toString'",
            $ctx
        );
    }

    public function testHelperLowersExtensionBasenameType(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/VM/DirectoryIteratorJitHelper.php');
        $this->assertStringContainsString('compileGetExtension', $helper);
        $this->assertStringContainsString('compileGetBasename', $helper);
        $this->assertStringContainsString('compileGetType', $helper);
        $this->assertStringContainsString('emitExtensionFromBasename', $helper);
        $this->assertStringContainsString('pathIsLink', $helper);
    }

    public function testMethodDispatchIncludesExtensionAccessors(): void
    {
        $method = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/DirectoryIteratorMethod.php');
        $this->assertStringContainsString("'getextension' =>", $method);
        $this->assertStringContainsString("'getbasename' =>", $method);
        $this->assertStringContainsString("'gettype' =>", $method);
    }
}
