<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class GetDeclaredArity27900Test extends TestCase
{
    public function testReflectionArityZeroOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            // Re-read gate after profile change — CompilerVersion reads getenv each call.
            $this->assertFalse(\PHPCompiler\CompilerVersion::supportsGetDeclaredExcludeDeprecated());
            $names = \PHPCompiler\BuiltinParamNames::forFunction('get_declared_classes');
            $this->assertSame([], $names);
            $names = \PHPCompiler\BuiltinParamNames::forFunction('get_declared_interfaces');
            $this->assertSame([], $names);
            $names = \PHPCompiler\BuiltinParamNames::forFunction('get_declared_traits');
            $this->assertSame([], $names);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
