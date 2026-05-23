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
        $this->assertSame(10, SelfHostBuiltinPolicy::autoStubBatchCount());
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
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('getenv'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('putenv'));
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('filter_var'));
        $this->assertSame('filter', SelfHostBuiltinPolicy::categoryFor('filter_input'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('filter_var'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('hash'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('preg_match'));
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
            'getenv',
            'putenv',
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
