<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class SelfHostBuiltinPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT');
        parent::tearDown();
    }

    public function testAutoStubBatchCountIsThirty(): void
    {
        $this->assertSame(30, SelfHostBuiltinPolicy::autoStubBatchCount());
    }

    public function testRequiredBundleCategories(): void
    {
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('dirname'));
        $this->assertSame('filesystem', SelfHostBuiltinPolicy::categoryFor('dirname'));
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertTrue(SelfHostBuiltinPolicy::shouldExternalStub('abs'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('dirname'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('fopen'));
    }

    public function testCompileFuncRegistersExternalMethodStub(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $runtime = new Runtime(Runtime::MODE_AOT);
        $runtime->loadJit();
        $this->assertInstanceOf(ExternalMethod::class, $runtime->loadJitContext()->functionProxies['abs']);
    }
}
