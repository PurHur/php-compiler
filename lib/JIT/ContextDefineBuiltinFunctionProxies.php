<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Thin-AOT Call / functionProxies catalog hub for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltins} so proxy wiring stays a separate
 * TU from builtin register/implement/initialize (split-TU / size-budget ratchet).
 * Peer catalogs: {@see ContextDefineBuiltinFunctionProxiesSplContainers},
 * {@see ContextDefineBuiltinFunctionProxiesSplIterators},
 * {@see ContextDefineBuiltinFunctionProxiesDirectoryAndFile},
 * {@see ContextDefineBuiltinFunctionProxiesWeakAndPhpToken},
 * {@see ContextDefineBuiltinFunctionProxiesReflectionAndException},
 * {@see ContextDefineBuiltinFunctionProxiesExceptionAndError},
 * {@see ContextDefineBuiltinFunctionProxiesDateAndXml},
 * {@see ContextDefineBuiltinFunctionProxiesFinfoPdoAndXml} (#36387 / #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxies;} on {@see Context}.
 * Invoked from {@see ContextDefineBuiltins::defineBuiltins} after implement.
 *
 * No new C ABI. php-src analogy: zim_* method tables and internal function
 * entries are registered beside the executor (ext/spl/spl_*.c, ext/date/,
 * Zend/zend_builtin_functions.c) rather than inside MINIT implement loops.
 */
trait ContextDefineBuiltinFunctionProxies
{
    private function defineBuiltinFunctionProxies(): void
    {
        $this->functionProxies['is_null'] = new Builtin\IsNullFn();
        $this->functionProxies['phpcompiler\\is_null'] = new Builtin\IsNullFn();
        // SplObjectStorage / PriorityQueue / Dllist / FixedArray — ContextDefineBuiltinFunctionProxiesSplContainers (#36387).
        $this->defineBuiltinFunctionProxiesSplContainers();
        // ArrayIterator / ArrayObject / LimitIterator family — ContextDefineBuiltinFunctionProxiesSplIterators (#36387).
        $this->defineBuiltinFunctionProxiesSplIterators();
        // Directory / SplFile* / GlobIterator — ContextDefineBuiltinFunctionProxiesDirectoryAndFile (#36387).
        $this->defineBuiltinFunctionProxiesDirectoryAndFile();
        // WeakReference / WeakMap / SensitiveParameterValue / PhpToken —
        // ContextDefineBuiltinFunctionProxiesWeakAndPhpToken (#36387).
        $this->defineBuiltinFunctionProxiesWeakAndPhpToken();

        // BcMath\Number thin-AOT Call proxies: registered by ext/bcmath Module::jitInit (#36204).

        // Reflection* — ContextDefineBuiltinFunctionProxiesReflectionAndException (#36387).
        $this->defineBuiltinFunctionProxiesReflectionAndException();
        // Exception / Throwable / Error — ContextDefineBuiltinFunctionProxiesExceptionAndError (#36387).
        $this->defineBuiltinFunctionProxiesExceptionAndError();

        FiberHelper::registerJitMethods($this);
        GeneratorHelper::registerJitMethods($this);
        ClosureBindHelper::registerJitMethods($this);
        $this->defineBuiltinFunctionProxiesDateAndXml();
        // finfo / PDO / XMLReader / XMLWriter / Dom\TokenList —
        // ContextDefineBuiltinFunctionProxiesFinfoPdoAndXml (#36387).
        $this->defineBuiltinFunctionProxiesFinfoPdoAndXml();
    }
}
