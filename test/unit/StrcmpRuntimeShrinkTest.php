<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * User-script strcmp() stays PHP-in-PHP via VmString / JitStringCompare (#31971).
 * Libc strcmp(3) is module-local after the LibcExtern always-on drop.
 */
final class StrcmpRuntimeShrinkTest extends TestCase
{
    public function testUserScriptStrcmpUsesPhpBridgeNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/strcmp.php');
        $this->assertStringContainsString('VmString::strcmp', $builtin);
        $this->assertStringContainsString('JitStringCompare::strcmp', $builtin);
        $this->assertStringNotContainsString("lookupFunction('strcmp')", $builtin);
    }

    public function testVmStringStrcmpMatchesPhp(): void
    {
        $this->assertLessThan(0, VmString::strcmp('abc', 'abd'));
        $this->assertSame(0, VmString::strcmp('abc', 'abc'));
        $this->assertGreaterThan(0, VmString::strcmp('abd', 'abc'));
    }

    public function testLibcExternDropsAlwaysOnStrcmp(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strcmp' =>", $source);
        $this->assertStringContainsString('ensureStrcmpDecl', $source);
        $this->assertStringContainsString('#31971', $source);
    }

    public function testVmValueCompareEnsuresStrcmpDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueCompare.php');
        $this->assertStringContainsString("lookupFunction('strcmp')", $source);
        $this->assertStringContainsString('LibcExtern::ensureStrcmpDecl', $source);
        $this->assertStringContainsString('#31971', $source);
    }
}
