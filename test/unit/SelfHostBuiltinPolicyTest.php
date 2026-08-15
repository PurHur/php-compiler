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
        $this->assertSame(0, SelfHostBuiltinPolicy::autoStubBatchCount());
    }

    public function testRequiredBundleCategories(): void
    {
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('dirname'));
        $this->assertSame('filesystem', SelfHostBuiltinPolicy::categoryFor('dirname'));
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('abs'));
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('abs'));
        $this->assertSame('numeric', SelfHostBuiltinPolicy::categoryFor('abs'));
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('hrtime'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('hrtime'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('pack'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('copy'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('dirname'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('mkdir'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('file_put_contents'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('fopen'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('getenv'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('putenv'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('ini_set'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('ini_get'));
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('filter_var'));
        $this->assertSame('filter', SelfHostBuiltinPolicy::categoryFor('filter_input'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('filter_var'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('hash'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('preg_match'));
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('shell_exec'));
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('session_start'));
        $this->assertSame('session', SelfHostBuiltinPolicy::categoryFor('session_start'));
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('session_start'));
        $this->assertSame('process', SelfHostBuiltinPolicy::categoryFor('shell_exec'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('shell_exec'));
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('trait_exists'));
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('interface_exists'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('trait_exists'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('interface_exists'));
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
            'usort',
            'filter_var',
            'getenv',
            'putenv',
            'ini_set',
            'ini_get',
            'shell_exec',
            'escapeshellarg',
            'escapeshellcmd',
            'phpc_run_command',
            'gettype',
            'get_debug_type',
            'var_export',
            'headers_list',
            'getallheaders',
            'apache_request_headers',
        ] as $fn) {
            $this->assertTrue(
                SelfHostBuiltinPolicy::isRequiredForBundle($fn),
                $fn.' must stay on real JIT lowering for bootstrap wave 12'
            );
            $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub($fn), $fn);
        }
    }

    public function testFormerAutoStubBatchUsesRealLowering(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $runtime = new Runtime(Runtime::MODE_AOT);
        $runtime->loadJit();
        $proxies = $runtime->loadJitContext()->functionProxies;
        $this->assertNotInstanceOf(ExternalMethod::class, $proxies['abs']);
        $this->assertNotInstanceOf(ExternalMethod::class, $proxies['pack']);
        $this->assertNotInstanceOf(ExternalMethod::class, $proxies['copy']);
    }

    public function testUnlistedStdlibStillExternalStub(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $runtime = new Runtime(Runtime::MODE_AOT);
        $runtime->loadJit();
        $this->assertInstanceOf(ExternalMethod::class, $runtime->loadJitContext()->functionProxies['sin']);
    }
}
