<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** #28616 — free-function isAnonymousClass() absent on php-src-strict profiles. */
final class Issue28616IsAnonymousClassPhantomTest extends TestCase
{
    public function testPhantomAbsentOnProfile82Through85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.2', '8.3', '8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsIsAnonymousClass(), $profile);
            $this->assertFalse(CompilerVersion::advertisesIsAnonymousClass(), $profile);
            $this->assertFalse(
                BuiltinIntrospectionPolicy::functionIsAdvertised('isAnonymousClass'),
                $profile
            );

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['isanonymousclass']), $profile);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'isAnonymousClass'),
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
