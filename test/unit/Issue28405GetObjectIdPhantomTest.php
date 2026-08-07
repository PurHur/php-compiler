<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** #28405 — get_object_id absent on php-src-strict forward profiles. */
final class Issue28405GetObjectIdPhantomTest extends TestCase
{
    public function testPhantomAbsentOnProfile84And85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsGetObjectId(), $profile);
            $this->assertFalse(CompilerVersion::advertisesGetObjectId(), $profile);
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('get_object_id'), $profile);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['get_object_id']), $profile);
            $this->assertTrue(isset($ctx->functions['spl_object_id']), $profile);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'get_object_id'),
                $profile
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'spl_object_id'),
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
