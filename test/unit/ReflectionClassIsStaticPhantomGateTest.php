<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/**
 * ReflectionClass::isStatic must stay unregistered on every profile (#28518).
 *
 * php-src: ext/reflection/php_reflection.stub.php — isStatic on
 * ReflectionFunctionAbstract / ReflectionProperty only (static-class RFC unmerged).
 */
final class ReflectionClassIsStaticPhantomGateTest extends TestCase
{
    public function testIsStaticAbsentOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionClassPhp84Apis());

        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $methods = $ctx->classes['reflectionclass']->methods;

        $this->assertArrayNotHasKey('isstatic', $methods);
        $this->assertArrayHasKey('isstatic', $ctx->classes['reflectionproperty']->methods);
        $this->assertArrayHasKey('isstatic', $ctx->classes['reflectionmethod']->methods);
    }

    public function testIsStaticAbsentOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionClassPhp84Apis());

            $ctx = new Context(new Runtime());
            BuiltinClasses::register($ctx);
            $rc = $ctx->classes['reflectionclass']->methods;

            $this->assertArrayNotHasKey('isstatic', $rc, 'ReflectionClass::isStatic must not register (#28518)');
            $this->assertArrayHasKey('getdeprecatedmessage', $rc);
            $this->assertArrayHasKey('getdeprecatedversion', $rc);
            // getReadOnlyProperties / getLazyPropertyNames are phantoms vs php-src (#28516).
            $this->assertArrayNotHasKey('getreadonlyproperties', $rc);
            $this->assertArrayNotHasKey('getlazypropertynames', $rc);

            $this->assertArrayHasKey('isstatic', $ctx->classes['reflectionproperty']->methods);
            $this->assertArrayHasKey('isstatic', $ctx->classes['reflectionmethod']->methods);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
