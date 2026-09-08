<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * WeakReference / WeakMap / SensitiveParameterValue / PhpToken thin-AOT Call
 * proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so the weak-ref /
 * attribute / tokenizer catalog stays a separate TU from SPL container /
 * iterator / DirectoryAndFile wiring (split-TU / size-budget ratchet toward
 * ContextDefineBuiltinFunctionProxies ≤ 50 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesWeakAndPhpToken;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after DirectoryAndFile, before ReflectionAndException.
 *
 * No new C ABI. php-src analogy: zim_WeakReference_* / zim_WeakMap_* live in
 * Zend/zend_weakrefs.c; SensitiveParameterValue in Zend/zend_attributes.c;
 * PhpToken in ext/tokenizer/tokenizer.c — beside the executor rather than
 * inside a monolithic MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesWeakAndPhpToken
{
    private function defineBuiltinFunctionProxiesWeakAndPhpToken(): void
    {
        $this->functionProxies['weakreference::create'] = new Call\WeakReferenceCreate();
        $this->functionProxies['weakreference::get'] = new Call\WeakReferenceGet();
        $this->functionProxies['sensitiveparametervalue::__construct'] = new Call\SensitiveParameterValueConstruct();
        $this->functionProxies['sensitiveparametervalue::getvalue'] = new Call\SensitiveParameterValueGetValue();
        $this->functionProxies['weakmap::offsetset'] = new Call\WeakMapMethod('offsetset');
        $this->functionProxies['weakmap::offsetget'] = new Call\WeakMapMethod('offsetget');
        $this->functionProxies['weakmap::offsetexists'] = new Call\WeakMapMethod('offsetexists');
        $this->functionProxies['weakmap::offsetunset'] = new Call\WeakMapMethod('offsetunset');
        $this->functionProxies['weakmap::count'] = new Call\WeakMapMethod('count');

        // PhpToken OOP API — user-script AOT (#27263 / #6794).
        $this->functionProxies['phptoken::__construct'] = new Call\PhpTokenConstruct();
        $this->functionProxies['phptoken::tokenize'] = new Call\PhpTokenTokenize();
        $this->functionProxies['phptoken::gettokenname'] = new Call\PhpTokenGetTokenName();
    }
}
