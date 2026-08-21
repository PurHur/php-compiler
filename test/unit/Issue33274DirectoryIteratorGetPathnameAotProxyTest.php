<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT proxies for SplFileInfo::getPathname / getPath / __toString (#33274).
 *
 * php-src: ext/spl/spl_directory.c — zim_SplFileInfo_getPathname
 */
final class Issue33274DirectoryIteratorGetPathnameAotProxyTest extends TestCase
{
    public function testContextRegistersPathnameProxies(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'getPathname', 'getPath', '__toString'", $ctx);
    }

    public function testHelperLowersPathnameViaJoin(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/VM/DirectoryIteratorJitHelper.php');
        $this->assertStringContainsString('compileGetPathname', $helper);
        $this->assertStringContainsString('compileGetPath', $helper);
        $this->assertStringContainsString('emitJoinedPathname', $helper);
    }

    public function testMethodDispatchIncludesPathnameAccessors(): void
    {
        $method = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/DirectoryIteratorMethod.php');
        $this->assertStringContainsString("'getpathname' =>", $method);
        $this->assertStringContainsString("'getpath' =>", $method);
        $this->assertStringContainsString("'__tostring' =>", $method);
    }
}
