<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** Forward-profile callability vs reference introspection gates (#16086). */
final class ForwardProfilePhantomIntrospectionTest extends TestCase
{
    public function testFpowCallableButNotAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsFpow());
            $this->assertFalse(CompilerVersion::advertisesFpow());
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('fpow'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['fpow']));
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'fpow')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testBcmathCallableButExtensionNotAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBcmath());
            $this->assertFalse(CompilerVersion::advertisesBcmath());
            $this->assertFalse(
                \PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('bcmath')
            );

            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['bcadd']));
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'bcadd')
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
