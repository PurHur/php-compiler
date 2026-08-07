<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Capability matrix advertisement gating (#11904). */
final class CapabilityMatrixAdvertisementTest extends TestCase
{
    public function testMatrixVmColumnMatchesFunctionExists(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/capability-matrix.php';

        $capabilities = applyBuiltinCapabilityCurations(
            applyBuiltinAdvertisementParity(collectCapabilities($root), $root)
        );

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach ($capabilities as $name => $row) {
            $exists = isset($ctx->functions[$name]);
            $this->assertSame(
                $exists,
                $row['vm'],
                sprintf('capability matrix vm drift for %s', $name)
            );
        }
    }

    public function testWithheldFpowFamilyDocumentedWhenGateOff(): void
    {
        if (CompilerVersion::supportsFpow()) {
            $this->markTestSkipped('fpow registered on this profile');
        }

        $root = dirname(__DIR__, 2);
        require_once $root.'/script/capability-matrix.php';

        $capabilities = applyBuiltinCapabilityCurations(
            applyBuiltinAdvertisementParity(collectCapabilities($root), $root)
        );

        $this->assertArrayHasKey('fpow', $capabilities);
        $this->assertFalse($capabilities['fpow']['vm'], 'fpow');
        $this->assertNotEmpty($capabilities['fpow']['notes']);

        foreach (['fmin', 'fmax', 'fadd', 'fsub', 'fmul', 'nextafter'] as $fn) {
            $this->assertArrayHasKey($fn, $capabilities);
            $this->assertFalse($capabilities[$fn]['vm'], $fn);
            $this->assertNotEmpty($capabilities[$fn]['notes']);
        }
    }
}
