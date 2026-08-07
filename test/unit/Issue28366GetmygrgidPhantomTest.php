<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** #28366 — getmygrgid absent on php-src-strict forward profiles. */
final class Issue28366GetmygrgidPhantomTest extends TestCase
{
    public function testPhantomAbsentOnProfile84And85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsGetmygrgid(), $profile);
            $this->assertFalse(CompilerVersion::advertisesGetmygrgid(), $profile);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['getmygrgid']), $profile);
            $this->assertTrue(isset($ctx->functions['getmygid']), $profile);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'getmygrgid'),
                $profile
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'getmygid'),
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
