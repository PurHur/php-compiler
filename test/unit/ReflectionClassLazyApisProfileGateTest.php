<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/**
 * ReflectionClass lazy-object methods gated on supportsLazyObjectFactories (#25503).
 *
 * php-src: Zend/zend_lazy_objects.c / ext/reflection/php_reflection.c (since 8.4.0).
 */
final class ReflectionClassLazyApisProfileGateTest extends TestCase
{
    /** @var list<string> */
    private const LAZY_METHODS = [
        'newlazyghost',
        'newlazyproxy',
        'isuninitializedlazyobject',
        'resetaslazyghost',
        'getlazyinitializer',
        'initializelazyobject',
        'marklazyobjectasinitialized',
        'createlazyghost',
        'createlazyproxy',
        'resetaslazyproxy',
        'resetaslazyobject',
    ];

    public function testLazyMethodsAbsentOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsLazyObjectFactories());

        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $methods = $ctx->classes['reflectionclass']->methods;

        foreach (self::LAZY_METHODS as $name) {
            $this->assertArrayNotHasKey($name, $methods, $name.' must be withheld on 8.2 reference');
        }
    }

    public function testLazyMethodsPresentOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsLazyObjectFactories());

            $ctx = new Context(new Runtime());
            BuiltinClasses::register($ctx);
            $methods = $ctx->classes['reflectionclass']->methods;

            foreach (self::LAZY_METHODS as $name) {
                $this->assertArrayHasKey($name, $methods, $name.' must be registered on PROFILE=8.4');
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
