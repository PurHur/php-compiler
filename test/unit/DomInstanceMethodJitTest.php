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

    public function testRecognizesLivingAttrRenameProxyUnderUserScriptAot(): void
    {
        // USER_SCRIPT_DIRECT_METHODS path (UserScriptAotEnv) — #27108.
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        try {
            $this->assertTrue(DomInstanceMethodJit::shouldDeferToVmClassMethodLowering());
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('dom\\attr::rename'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('dom\\element::rename'));
            $this->assertFalse(DomInstanceMethodJit::isDomInstanceMethodProxy('object::rename'));
        } finally {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
        }
    }

    public function testRecognizesDomAttrIsIdProxyUnderUserScriptAot(): void
    {
        // USER_SCRIPT_DIRECT_METHODS — AOT isId must not ExternalMethod-null (#29884).
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        try {
            $this->assertTrue(DomInstanceMethodJit::shouldDeferToVmClassMethodLowering());
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domattr::isid'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('dom\\attr::isid'));
            $this->assertFalse(DomInstanceMethodJit::isDomInstanceMethodProxy('object::isid'));
        } finally {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
        }
    }

    public function testRecognizesDomSubstringDataProxyUnderUserScriptAot(): void
    {
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        try {
            $this->assertTrue(DomInstanceMethodJit::shouldDeferToVmClassMethodLowering());
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domtext::substringdata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domcharacterdata::substringdata'));
            $this->assertFalse(DomInstanceMethodJit::isDomInstanceMethodProxy('object::substringdata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domtext::appenddata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domcharacterdata::appenddata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domtext::insertdata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domcharacterdata::insertdata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domtext::deletedata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domcharacterdata::deletedata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domtext::replacedata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domcharacterdata::replacedata'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domtext::iswhitespaceinelementcontent'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domtext::iselementcontentwhitespace'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::setattributens'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::hasattributens'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::removeattributens'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domdocument::getelementsbytagnamens'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::getelementsbytagname'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::haschildnodes'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::haschildnodes'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::hasattributes'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::hasattributes'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::getnodepath'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::getnodepath'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::getlineno'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::getlineno'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::issupported'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::issupported'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::lookupprefix'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::lookupprefix'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domnode::lookupnamespaceuri'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domelement::lookupnamespaceuri'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('domimplementation::hasfeature'));
            $this->assertTrue(DomInstanceMethodJit::isDomInstanceMethodProxy('dom\\implementation::hasfeature'));
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
