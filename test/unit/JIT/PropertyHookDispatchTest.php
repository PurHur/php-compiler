<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

final class PropertyHookDispatchTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }


public function testHookMethodNamesMatchPreprocessor(): void
    {
        self::assertSame('__phpc_property_set_email', PropertyHooks::setHookMethodName('email'));
        self::assertSame('__phpc_property_get_email', PropertyHooks::getHookMethodName('email'));
        self::assertSame('email', PropertyHooks::propertyNameFromSetHookMethod('__phpc_property_set_email'));
        self::assertSame('email', PropertyHooks::propertyNameFromGetHookMethod('__phpc_property_get_email'));
        self::assertNull(PropertyHooks::propertyNameFromGetHookMethod('__phpc_property_set_email'));
    }
}
