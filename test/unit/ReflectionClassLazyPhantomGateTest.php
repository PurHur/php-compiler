<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/**
 * ReflectionClass phantom lazy/readonly helpers must stay unregistered (#28516).
 *
 * php-src: ext/reflection/php_reflection.stub.php — newLazyGhost/newLazyProxy +
 * reset/initialize helpers only; no createLazy*, getReadOnlyProperties,
 * getLazyPropertyNames, resetAsLazyObject, getLazyInitializationException,
 * getLazyProxyFactory.
 */
final class ReflectionClassLazyPhantomGateTest extends TestCase
{
    /** @var list<string> */
    private const PHANTOMS = [
        'createlazyghost',
        'createlazyproxy',
        'getlazypropertynames',
        'getreadonlyproperties',
        'resetaslazyobject',
        'getlazyinitializationexception',
        'getlazyproxyfactory',
    ];

    /** @var list<string> */
    private const REAL_LAZY = [
        'newlazyghost',
        'newlazyproxy',
        'resetaslazyghost',
        'resetaslazyproxy',
        'initializelazyobject',
        'isuninitializedlazyobject',
        'marklazyobjectasinitialized',
        'getlazyinitializer',
    ];

    public function testPhantomsAbsentOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsLazyObjectFactories());

        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $methods = $ctx->classes['reflectionclass']->methods;

        foreach (self::PHANTOMS as $name) {
            $this->assertArrayNotHasKey($name, $methods, $name);
        }
        foreach (self::REAL_LAZY as $name) {
            $this->assertArrayNotHasKey($name, $methods, $name.' withheld on 8.2 reference');
        }
    }

    public function testPhantomsAbsentOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsLazyObjectFactories());

            $ctx = new Context(new Runtime());
            BuiltinClasses::register($ctx);
            $rc = $ctx->classes['reflectionclass']->methods;

            foreach (self::PHANTOMS as $name) {
                $this->assertArrayNotHasKey($name, $rc, $name.' must not register (#28516)');
            }
            foreach (self::REAL_LAZY as $name) {
                $this->assertArrayHasKey($name, $rc, $name.' must remain on PROFILE=8.4');
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
