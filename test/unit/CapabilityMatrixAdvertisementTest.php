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

    public function testClassMethodMatrixUsesProxyIndexNotVmClassMethodStub(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/capability-matrix.php';

        $classMethods = collectClassMethodCapabilities(
            $root,
            buildJitClassMethodProxyIndex($root)
        );

        $this->assertArrayHasKey('SQLite3::exec', $classMethods);
        $this->assertTrue($classMethods['SQLite3::exec']['jit']);
        $this->assertSame('fold', $classMethods['SQLite3::exec']['aot']);

        $this->assertArrayHasKey('XMLReader::read', $classMethods);
        $this->assertTrue($classMethods['XMLReader::read']['jit']);
        $this->assertSame('fold', $classMethods['XMLReader::read']['aot']);
    }

    public function testFunctionAotIsNotBlindJitCopyWhenDeferred(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/capability-matrix.php';

        $capabilities = collectCapabilities($root);
        if (!isset($capabilities['connection_aborted'])) {
            $this->markTestSkipped('connection_aborted not in matrix');
        }
        if ($capabilities['connection_aborted']['jit']) {
            $this->assertTrue($capabilities['connection_aborted']['aot']);
        }
    }
}
