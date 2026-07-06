<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPCompiler\ext\standard\BuiltinRegistry;
use PHPCompiler\ext\standard\VmFastCgi;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** fastcgi_finish_request() SAPI gating — phantom in CLI per php-src basic_functions.c (#16757). */
final class FastCgiFinishRequestCliPhantomTest extends TestCase
{
    protected function tearDown(): void
    {
        VmFastCgi::clearFastCgiRequestActive();
        BuiltinRegistry::resetForTest();
    }

    public function testPlainCliDoesNotRegisterFastcgiFinishRequest(): void
    {
        VmFastCgi::clearFastCgiRequestActive();

        $this->assertFalse(VmFastCgi::registersFinishRequestFunction());
        $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('fastcgi_finish_request'));
        $this->assertNotContains('fastcgi_finish_request', BuiltinRegistry::sortedNames());

        $runtime = new Runtime();
        $this->assertArrayNotHasKey('fastcgi_finish_request', $runtime->vmContext->functions);
    }

    public function testFastCgiActiveRegistersFinishRequestFunction(): void
    {
        VmFastCgi::markFastCgiRequestActive();

        $this->assertTrue(VmFastCgi::registersFinishRequestFunction());
        $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('fastcgi_finish_request'));
        $this->assertContains('fastcgi_finish_request', BuiltinRegistry::sortedNames());

        $runtime = new Runtime();
        $this->assertArrayHasKey('fastcgi_finish_request', $runtime->vmContext->functions);
    }
}
