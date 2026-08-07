<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** #28565 — fadd/fsub/fmul/fmax/fmin/nextafter absent on php-src-strict forward profiles. */
final class Issue28565IeeeFloatOpPhantomsTest extends TestCase
{
    public function testPhantomsAbsentOnProfile84And85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        $phantoms = ['fadd', 'fsub', 'fmul', 'fmax', 'fmin', 'nextafter'];
        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsIeeeFloatOpPhantoms(), $profile);
            $this->assertFalse(CompilerVersion::advertisesIeeeFloatOpPhantoms(), $profile);
            $this->assertFalse(CompilerVersion::supportsNextafter(), $profile);
            $this->assertFalse(CompilerVersion::advertisesNextafter(), $profile);
            $this->assertTrue(CompilerVersion::supportsFpow(), $profile);
            $this->assertTrue(CompilerVersion::advertisesFpow(), $profile);

            foreach ($phantoms as $fn) {
                $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised($fn), "{$profile}:{$fn}");
            }
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('fpow'), $profile);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach ($phantoms as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), "{$profile}:{$fn}");
                $this->assertFalse(
                    \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, $fn),
                    "{$profile}:{$fn}"
                );
            }
            $this->assertTrue(isset($ctx->functions['fpow']), $profile);
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'fpow'),
                $profile
            );
            $this->assertTrue(isset($ctx->functions['fdiv']), $profile);
            $this->assertTrue(isset($ctx->functions['fmod']), $profile);
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }
}
