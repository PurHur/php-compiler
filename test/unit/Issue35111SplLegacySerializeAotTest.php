<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35111 — thin AOT must proxy SPL Serializable::serialize()/unserialize()
 * (otherwise silent null #579).
 */
final class Issue35111SplLegacySerializeAotTest extends TestCase
{
    public function testArrayObjectAndDllistProxiesRegistered(): void
    {
        $ctx = file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertNotFalse($ctx);
        $this->assertStringContainsString("'serialize'", $ctx);
        $this->assertStringContainsString("'unserialize'", $ctx);
        $this->assertStringContainsString('#35111', $ctx);
    }

    public function testLegacyHelpersExist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/ext/standard/UnserializeSplArrayLegacyNestedJitHelper.php');
        $this->assertFileExists($root.'/ext/standard/UnserializeSplDllistLegacyNestedJitHelper.php');
        $ao = file_get_contents($root.'/lib/VM/ArrayObjectJitHelper.php');
        $dll = file_get_contents($root.'/lib/VM/SplDllistJitHelper.php');
        $this->assertStringContainsString('compileLegacySerialize', $ao);
        $this->assertStringContainsString('compileLegacyUnserialize', $ao);
        $this->assertStringContainsString('compileLegacySerialize', $dll);
        $this->assertStringContainsString('compileLegacyUnserialize', $dll);
    }
}
