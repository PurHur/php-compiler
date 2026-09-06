<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPUnit\Framework\TestCase;

/**
 * #36399: helper-runtime UNITS_SHA256SUMS byte ledger.
 */
final class HelperRuntimeUnitChecksumsTest extends TestCase
{
    public function testLibWriteAndVerifyRoundTripInTempArch(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root.'/script/helper-runtime-unit-checksums-lib.php';

        $tmp = sys_get_temp_dir().'/phpc-units-sha-'.bin2hex(random_bytes(4));
        $unitDir = $tmp.'/units/fixture_unit';
        mkdir($unitDir, 0775, true);
        file_put_contents($unitDir.'/unit.o', "object-bytes\n");
        file_put_contents($unitDir.'/unit.bc', "bitcode-bytes\n");

        try {
            $n = helper_runtime_write_units_sha256sums($tmp);
            $this->assertSame(2, $n);
            $this->assertFileExists(helper_runtime_units_sha256sums_path($tmp));
            $this->assertSame([], helper_runtime_verify_units_sha256sums($tmp, true));

            file_put_contents($unitDir.'/unit.o', "tampered\n");
            $errors = helper_runtime_verify_units_sha256sums($tmp, true);
            $this->assertNotSame([], $errors);
            $this->assertStringContainsString('sha256 mismatch', $errors[0]);
        } finally {
            @unlink($unitDir.'/unit.o');
            @unlink($unitDir.'/unit.bc');
            @unlink(helper_runtime_units_sha256sums_path($tmp));
            @rmdir($unitDir);
            @rmdir($tmp.'/units');
            @rmdir($tmp);
        }
    }

    public function testPrelinkAndPublishRewriteLedger(): void
    {
        $root = dirname(__DIR__, 3);
        $emit = (string) file_get_contents($root.'/script/emit-helper-runtime-object.php');
        $publish = (string) file_get_contents($root.'/script/publish-helper-units-prelink.php');
        $check = (string) file_get_contents($root.'/script/check-helper-runtime-prelink.php');
        $this->assertStringContainsString('helper_runtime_write_units_sha256sums', $emit);
        $this->assertStringContainsString('helper_runtime_write_units_sha256sums', $publish);
        $this->assertStringContainsString('helper_runtime_verify_units_sha256sums', $check);
        // Targeted publish must merge arch manifest (preserve common_object_sha256).
        $this->assertStringContainsString('array_merge($existingManifest', $publish);
    }
}
