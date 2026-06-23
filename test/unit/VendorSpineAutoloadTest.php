<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\VendorSpineAutoload;

final class VendorSpineAutoloadTest extends TestCase
{
    public function testResolvesPhpCfgLivenessDetectorPath(): void
    {
        $root = dirname(__DIR__, 2);
        $path = VendorSpineAutoload::resolveClassPath('PHPCfg\\LivenessDetector', $root, [
            'PHPCfg\\' => 'vendor/ircmaxell/php-cfg/lib/PHPCfg/',
        ]);
        $this->assertNotNull($path);
        $this->assertStringEndsWith('PHPCfg/LivenessDetector.php', str_replace('\\', '/', $path));
    }

    public function testVmAutoloadRegistersNullSafeLivenessDetectorParent(): void
    {
        $runtime = new Runtime();
        $runtime->vm()->executeCompileUnit(
            $root = dirname(__DIR__, 2).'/lib/NullSafeLivenessDetector.php'
        );
        $this->assertArrayHasKey(
            'phpcfg\\livenessdetector',
            $runtime->vmContext->classes
        );
        $this->assertArrayHasKey(
            'phpcompiler\\nullsafelivenessdetector',
            $runtime->vmContext->classes
        );
    }
}
