<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Call\DomInstanceMethod;
use PHPCompiler\JIT\DomInstanceMethodJit;
use PHPUnit\Framework\TestCase;

/** ext/dom instance-method JIT proxy registration (#17130). */
final class DomInstanceMethodJitTest extends TestCase
{
    public function testRecognizesDomInstanceMethodProxyNames(): void
    {
        $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domdocument::createelement'));
        $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('DOMElement::setAttribute'));
        $this->assertFalse(DomInstanceMethodJit::isDomInstanceMethodProxy('splobjectstorage::attach'));
    }

    public function testUserScriptRecognizesAppendChildProxies(): void
    {
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        try {
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::appendchild'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domdocument::appendchild'));
        } finally {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
        }
    }

    public function testEnsureProxyRegistersCallableLowering(): void
    {
        $this->markTestSkipped('loadJitContext() is too heavy for default unit gate (#17130)');
        $runtime = new Runtime();
        $ctx = $runtime->loadJitContext();
        DomInstanceMethodJit::ensureProxy($ctx, 'domdocument::createelement');
        $this->assertArrayHasKey('domdocument::createelement', $ctx->functionProxies);
        $this->assertInstanceOf(DomInstanceMethod::class, $ctx->functionProxies['domdocument::createelement']);
        $this->assertTrue($ctx->functionIsRegistered('domdocument::createelement'));
    }
}
