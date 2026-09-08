<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Fiber / Generator / ClosureBind thin-AOT Call proxy registration for
 * {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so Fiber /
 * Generator / Closure method-table wiring stays a separate TU from the
 * is_null + peer-dispatch hub (split-TU / size-budget ratchet toward
 * ContextDefineBuiltinFunctionProxies ≤ 50 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesFiberGeneratorAndClosure;}
 * on {@see Context}. Invoked from
 * {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after ReflectionAndException, before DateAndXml.
 *
 * No new C ABI. php-src analogy: zim_Fiber_* / generator / Closure::bind
 * method tables live in Zend/zend_fibers.c, Zend/zend_generators.c, and
 * Zend/zend_closures.c beside the executor rather than inside a monolithic
 * MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesFiberGeneratorAndClosure
{
    private function defineBuiltinFunctionProxiesFiberGeneratorAndClosure(): void
    {
        FiberHelper::registerJitMethods($this);
        GeneratorHelper::registerJitMethods($this);
        ClosureBindHelper::registerJitMethods($this);
    }
}
