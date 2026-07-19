<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * request_parse_body VM path must not call NestedJIT-only phpc_getenv_kernel (#21112).
 */
final class RequestParseBodyGetenvShrinkTest extends TestCase
{
    public function testEngineOverlayUsesVmEnvNotKernelCallable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RequestParseBodyEngine.php');
        $this->assertStringContainsString('GetenvJitHelper::localOverlayEntries', $source);
        $this->assertStringContainsString('VmEnv::getenv', $source);
        $this->assertStringNotContainsString('GetenvJitHelper::getenv(', $source);
        $this->assertStringNotContainsString('\\phpc_getenv_kernel(', $source);
        $this->assertStringContainsString('abort-before-read', $source);
    }
}
