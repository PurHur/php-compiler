<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStreamWrapperRegistry;
use PHPUnit\Framework\TestCase;

/** VmStreamWrapperRegistry built-in unregister/restore (#12620, #12621). */
final class VmStreamWrapperRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        VmStreamWrapperRegistry::resetForTests();
    }

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

    /** php-src registration order, not alphabetical (#14211). */
    public function testGetWrappersPreservesRegistrationOrder(): void
    {
        $wrappers = VmStreamWrapperRegistry::getWrappers();
        $this->assertSame('https', $wrappers[0] ?? null);
        $this->assertSame(
            ['https', 'ftps', 'compress.zlib', 'php', 'file', 'glob', 'data', 'http', 'ftp', 'phar'],
            $wrappers
        );
        $this->assertTrue(VmStreamWrapperRegistry::register('var', 'VarStreamWrapper'));
        $this->assertSame('var', VmStreamWrapperRegistry::getWrappers()[\count($wrappers)]);
    }
}
