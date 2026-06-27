<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStreamWrapperRegistry;
use PHPUnit\Framework\TestCase;

/** VmStreamWrapperRegistry built-in unregister/restore (#12620, #12621). */
final class VmStreamWrapperRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        VmStreamWrapperRegistry::resetForTests();
        parent::tearDown();
    }

    public function testUnregisterBuiltinRemovesFromWrapperList(): void
    {
        $this->assertTrue(VmStreamWrapperRegistry::unregister('http'));
        $this->assertNotContains('http', VmStreamWrapperRegistry::getWrappers());
    }

    public function testRestoreAfterBuiltinUnregisterReListsScheme(): void
    {
        $this->assertTrue(VmStreamWrapperRegistry::unregister('http'));
        $this->assertTrue(VmStreamWrapperRegistry::restore('http'));
        $this->assertContains('http', VmStreamWrapperRegistry::getWrappers());
    }

    public function testRestoreNoopOnBuiltinReturnsTrueWithoutStack(): void
    {
        $this->assertTrue(VmStreamWrapperRegistry::restore('http'));
        $this->assertContains('http', VmStreamWrapperRegistry::getWrappers());
    }

    public function testUnregisterBuiltinTwiceReturnsFalse(): void
    {
        $this->assertTrue(VmStreamWrapperRegistry::unregister('http'));
        $this->assertFalse(VmStreamWrapperRegistry::unregister('http'));
    }
}
