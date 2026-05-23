<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPUnit\Framework\TestCase;

final class ArrayMapCallbackPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT');
        parent::tearDown();
    }

    public function testDeferredNoteDocumentsSubset(): void
    {
        $this->assertStringContainsString('closures', SelfHostBuiltinPolicy::ARRAY_MAP_CALLBACK_DEFERRED_NOTE);
        $this->assertStringContainsString('string builtin', SelfHostBuiltinPolicy::ARRAY_MAP_CALLBACK_DEFERRED_NOTE);
    }

    public function testArrayMapStaysOnRealLoweringForSelfHost(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('array_map'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('array_map'));
    }
}
