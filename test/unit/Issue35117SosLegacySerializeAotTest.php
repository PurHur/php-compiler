<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35117 — thin AOT must proxy SplObjectStorage::serialize()/unserialize()
 * (otherwise silent null #579; leftover of #35111).
 */
final class Issue35117SosLegacySerializeAotTest extends TestCase
{
    public function testProxiesRegistered(): void
    {
        $ctx = file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertNotFalse($ctx);
        $this->assertStringContainsString("splobjectstorage::serialize", $ctx);
        $this->assertStringContainsString("splobjectstorage::unserialize", $ctx);
        $this->assertStringContainsString('#35117', $ctx);
    }

    public function testLegacyHelpersExist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/ext/standard/UnserializeSplObjectStorageLegacyNestedJitHelper.php');
        $sos = file_get_contents($root.'/lib/VM/SplObjectStorageJitHelper.php');
        $this->assertStringContainsString('compileLegacySerialize', $sos);
        $this->assertStringContainsString('compileLegacyUnserialize', $sos);
        $this->assertStringContainsString('#35117', $sos);
        $call = file_get_contents($root.'/lib/JIT/Call/SplObjectStorageMethod.php');
        $this->assertStringContainsString("case 'serialize':", $call);
        $this->assertStringContainsString("case 'unserialize':", $call);
    }
}
