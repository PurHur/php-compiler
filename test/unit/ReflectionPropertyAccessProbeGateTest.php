<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/**
 * ReflectionProperty::{isReadable,isWritable} are PHP 8.5+ only (#28533).
 *
 * php-src: ext/reflection/php_reflection.stub.php — absent on PHP-8.4.
 */
final class ReflectionPropertyAccessProbeGateTest extends TestCase
{
    public function testAbsentOnReferenceAnd84Profiles(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyAccessProbes());

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsReflectionPropertyAccessProbes());

            $ctx = new Context(new Runtime());
            BuiltinClasses::register($ctx);
            $methods = $ctx->classes['reflectionproperty']->methods;
            $this->assertArrayNotHasKey('isreadable', $methods);
            $this->assertArrayNotHasKey('iswritable', $methods);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPresentWithStubArityOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyAccessProbes());

            $ctx = new Context(new Runtime());
            BuiltinClasses::register($ctx);
            $methods = $ctx->classes['reflectionproperty']->methods;
            $this->assertArrayHasKey('isreadable', $methods);
            $this->assertArrayHasKey('iswritable', $methods);

            $this->assertSame(
                ['scope', 'object='],
                BuiltinParamNames::forClassMethod('reflectionproperty::isreadable')
            );
            $this->assertSame(2, BuiltinParamNames::paramCountForInternalMethod('ReflectionProperty', 'isReadable'));
            $this->assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('ReflectionProperty', 'isReadable'));
            $this->assertSame(
                '?string',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionproperty', 'isreadable', 0)
            );
            $this->assertSame(
                '?object',
                BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('reflectionproperty', 'iswritable', 1)
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
