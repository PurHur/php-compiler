<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** #28365 — class_uses_recursive absent on php-src-strict forward profiles. */
final class Issue28365ClassUsesRecursivePhantomTest extends TestCase
{
    public function testPhantomAbsentOnProfile84And85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsClassUsesRecursive(), $profile);
            $this->assertFalse(CompilerVersion::advertisesClassUsesRecursive(), $profile);
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('class_uses_recursive'), $profile);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['class_uses_recursive']), $profile);
            $this->assertTrue(isset($ctx->functions['class_uses']), $profile);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'class_uses_recursive'),
                $profile
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'class_uses'),
                $profile
            );
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }
}
