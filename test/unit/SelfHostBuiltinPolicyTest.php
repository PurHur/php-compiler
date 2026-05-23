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

    public function testAutoStubBatchCount(): void
    {
        $this->assertSame(32, SelfHostBuiltinPolicy::autoStubBatchCount());
    }

    public function testRequiredBundleCategories(): void
    {
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('dirname'));
        $this->assertSame('filesystem', SelfHostBuiltinPolicy::categoryFor('dirname'));
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertTrue(SelfHostBuiltinPolicy::shouldExternalStub('abs'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('dirname'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('mkdir'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('file_put_contents'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('fopen'));
    }

    public function testWave12ArrayOpsUseRealLoweringUnderSelfHostAot(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        foreach ([
            'array_push',
            'array_unshift',
            'array_filter',
            'array_combine',
            'array_reverse',
            'compact',
            'extract',
            'sort',
            'filter_var',
        ] as $fn) {
            $this->assertTrue(
                SelfHostBuiltinPolicy::isRequiredForBundle($fn),
                $fn.' must stay on real JIT lowering for bootstrap wave 12'
            );
            $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub($fn), $fn);
        }
    }

    public function testCompileFuncRegistersExternalMethodStub(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $runtime = new Runtime(Runtime::MODE_AOT);
        $runtime->loadJit();
        $this->assertInstanceOf(ExternalMethod::class, $runtime->loadJitContext()->functionProxies['abs']);
    }
}
