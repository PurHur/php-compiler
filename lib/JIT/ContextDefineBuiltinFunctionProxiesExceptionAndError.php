<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Exception / Throwable / Error thin-AOT Call proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxiesReflectionAndException}
 * so the throwable catalog stays a separate TU from Reflection* wiring (split-TU /
 * size-budget ratchet toward ReflectionAndException ≤ 250 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesExceptionAndError;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after ReflectionAndException, before Fiber / Generator / ClosureBind helpers.
 *
 * No new C ABI. php-src analogy: zim_Exception_* / zend_exception_* method tables
 * live in Zend/zend_exceptions.c beside the executor rather than inside a
 * monolithic Reflection MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesExceptionAndError
{
    private function defineBuiltinFunctionProxiesExceptionAndError(): void
    {
        $this->functionProxies['exception::getmessage'] = new Call\ExceptionGetMessage('Exception');
        $this->functionProxies['exception::getcode'] = new Call\ExceptionGetCode('Exception');
        $exceptionToString = new Call\ExceptionToString();
        $exceptionGetTrace = new Call\ExceptionGetTrace('Exception');
        $exceptionGetTraceAsString = new Call\ExceptionGetTraceAsString('Exception');
        $exceptionGetFile = new Call\ExceptionGetFile('Exception');
        $exceptionGetLine = new Call\ExceptionGetLine('Exception');
        $exceptionGetPrevious = new Call\ExceptionGetPrevious('Exception');
        $this->functionProxies['exception::__tostring'] = $exceptionToString;
        $this->functionProxies['exception::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['exception::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['exception::getfile'] = $exceptionGetFile;
        $this->functionProxies['exception::getline'] = $exceptionGetLine;
        $this->functionProxies['exception::getprevious'] = $exceptionGetPrevious;
        // catch (Throwable $e) resolves methods on the interface name (#27333).
        $this->functionProxies['throwable::__tostring'] = $exceptionToString;
        $this->functionProxies['throwable::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['throwable::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['throwable::getmessage'] = $this->functionProxies['exception::getmessage'];
        $this->functionProxies['throwable::getcode'] = $this->functionProxies['exception::getcode'];
        $this->functionProxies['throwable::getfile'] = $exceptionGetFile;
        $this->functionProxies['throwable::getline'] = $exceptionGetLine;
        $this->functionProxies['throwable::getprevious'] = $exceptionGetPrevious;
        // Per-class ctor so TypeError wire text + $previous arg index match Zend (#28798).
        foreach (\PHPCompiler\ext\standard\ThrowableManifest::registrationOrder() as $throwableName) {
            if (!\PHPCompiler\ext\standard\ThrowableManifest::isAdvertised($throwableName)) {
                continue;
            }
            $lc = \PHPCompiler\ext\standard\ThrowableManifest::lcKey($throwableName);
            // ErrorException::__construct(..., $previous) is Argument #6; others #3.
            $prevArg = 'errorexception' === $lc ? 6 : 3;
            $this->functionProxies[$lc.'::__construct'] = new Call\ExceptionConstruct(
                $throwableName,
                $prevArg
            );
            $isErrorFamily = \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $lc
                || \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                    $lc,
                    \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                );
            // Throwable::__toString / getTrace / get* — user-script AOT (#26796, #27333, #30895).
            $this->functionProxies[$lc.'::__tostring'] = $exceptionToString;
            $this->functionProxies[$lc.'::gettrace'] = $isErrorFamily
                ? new Call\ExceptionGetTrace('Error')
                : $exceptionGetTrace;
            $this->functionProxies[$lc.'::gettraceasstring'] = $isErrorFamily
                ? new Call\ExceptionGetTraceAsString('Error')
                : $exceptionGetTraceAsString;
            $this->functionProxies[$lc.'::getmessage'] = $isErrorFamily
                ? new Call\ExceptionGetMessage('Error')
                : $this->functionProxies['exception::getmessage'];
            $this->functionProxies[$lc.'::getcode'] = $isErrorFamily
                ? new Call\ExceptionGetCode('Error')
                : $this->functionProxies['exception::getcode'];
            $this->functionProxies[$lc.'::getfile'] = $isErrorFamily
                ? new Call\ExceptionGetFile('Error')
                : $exceptionGetFile;
            $this->functionProxies[$lc.'::getline'] = $isErrorFamily
                ? new Call\ExceptionGetLine('Error')
                : $exceptionGetLine;
            $this->functionProxies[$lc.'::getprevious'] = $isErrorFamily
                ? new Call\ExceptionGetPrevious('Error')
                : $exceptionGetPrevious;
        }
        // Alias get* for Error family roots (same prop layout; Error ACE label #30895).
        $this->functionProxies['error::getmessage'] = new Call\ExceptionGetMessage('Error');
        $this->functionProxies['error::getcode'] = new Call\ExceptionGetCode('Error');
        $this->functionProxies['error::__tostring'] = $exceptionToString;
        $this->functionProxies['error::gettrace'] = new Call\ExceptionGetTrace('Error');
        $this->functionProxies['error::gettraceasstring'] = new Call\ExceptionGetTraceAsString('Error');
        $this->functionProxies['error::getfile'] = new Call\ExceptionGetFile('Error');
        $this->functionProxies['error::getline'] = new Call\ExceptionGetLine('Error');
        $this->functionProxies['error::getprevious'] = new Call\ExceptionGetPrevious('Error');
    }
}
