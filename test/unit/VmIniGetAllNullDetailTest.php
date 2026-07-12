<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\ext\standard\AssertOptionsJitHelper;
use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmIniIntrospection;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** ini_get_all() detail slots — unset directives NULL not '' (#17766, ext/standard/ini.c). */
final class VmIniGetAllNullDetailTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_INI_REGISTRY_JSON');
        VmIniIntrospection::resetIniSnapshotForTesting();
    }

    public function testAssertCallbackUnsetReportsNullInDetailTable(): void
    {
        $runtime = new Runtime();
        $table = VmIni::getAll($runtime->vmContext, null, true);
        $this->assertInstanceOf(HashTable::class, $table);
        $entry = $table->find('assert.callback');
        $this->assertNotNull($entry);
        $row = $entry->resolveIndirect()->toArray();
        $global = $row->find('global_value');
        $local = $row->find('local_value');
        $this->assertNotNull($global);
        $this->assertNotNull($local);
        $this->assertSame(Variable::TYPE_NULL, $global->resolveIndirect()->type);
        $this->assertSame(Variable::TYPE_NULL, $local->resolveIndirect()->type);
    }

    public function testRegistrySnapshotPreservesNullValues(): void
    {
        if (!function_exists('ini_get_all')) {
            $this->markTestSkipped('Host PHP lacks ini_get_all()');
        }

        putenv('PHP_COMPILER_INI_REGISTRY_JSON');
        VmIniIntrospection::resetIniSnapshotForTesting();
        VmIniIntrospection::seedHostIniRegistryFromZend();

        $entry = VmIniIntrospection::registryEntry('assert.callback');
        if (null === $entry) {
            $entry = VmIniIntrospection::registryEntry('error_log');
        }
        $this->assertNotNull($entry);
        $this->assertNull($entry['global_value']);
        $this->assertNull($entry['local_value']);
    }

    public function testAssertCallbackAfterIniSetReportsString(): void
    {
        $runtime = new Runtime();
        AssertOptionsJitHelper::setCallbackString('strlen');
        try {
            $table = VmIni::getAll($runtime->vmContext, null, true);
            $this->assertInstanceOf(HashTable::class, $table);
            $entry = $table->find('assert.callback');
            $this->assertNotNull($entry);
            $row = $entry->resolveIndirect()->toArray();
            $global = $row->find('global_value');
            $local = $row->find('local_value');
            $this->assertNotNull($global);
            $this->assertNotNull($local);
            $this->assertSame('strlen', $global->resolveIndirect()->toString());
            $this->assertSame('strlen', $local->resolveIndirect()->toString());
        } finally {
            AssertOptionsJitHelper::setCallbackString('');
        }
    }
}
