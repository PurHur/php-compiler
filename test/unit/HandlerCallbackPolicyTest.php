<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ExceptionHandlerCallbackPolicy;
use PHPCompiler\JIT\SplAutoloadCallbackPolicy;
use PHPUnit\Framework\TestCase;

final class HandlerCallbackPolicyTest extends TestCase
{
    public function testExceptionHandlerInvalidCallbackTypeErrorMatchesZendSubset(): void
    {
        $this->assertSame(
            'set_exception_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given',
            ExceptionHandlerCallbackPolicy::invalidCallbackTypeError()
        );
    }

    public function testSplAutoloadInvalidCallbackTypeErrorMatchesZendSubset(): void
    {
        $this->assertSame(
            'spl_autoload_register(): Argument #1 ($callback) must be a valid callback or null, no array or string given',
            SplAutoloadCallbackPolicy::invalidCallbackTypeError()
        );
    }

    public function testExceptionHandlerPhpSrcInvalidCallbackType(): void
    {
        $this->assertTrue(
            ExceptionHandlerCallbackPolicy::isPhpSrcInvalidCallbackType(\PHPCompiler\VM\Variable::TYPE_INTEGER)
        );
        $this->assertFalse(
            ExceptionHandlerCallbackPolicy::isPhpSrcInvalidCallbackType(\PHPCompiler\VM\Variable::TYPE_STRING)
        );
    }

    public function testSplAutoloadPhpSrcInvalidCallbackType(): void
    {
        $this->assertTrue(
            SplAutoloadCallbackPolicy::isPhpSrcInvalidCallbackType(\PHPCompiler\VM\Variable::TYPE_INTEGER)
        );
        $this->assertFalse(
            SplAutoloadCallbackPolicy::isPhpSrcInvalidCallbackType(\PHPCompiler\VM\Variable::TYPE_STRING)
        );
    }
}
