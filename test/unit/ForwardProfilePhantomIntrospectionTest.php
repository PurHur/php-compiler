<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** Forward-profile callability vs reference introspection gates (#16086). */
final class ForwardProfilePhantomIntrospectionTest extends TestCase
{
    public function testFpowCallableAndAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsFpow());
            $this->assertTrue(CompilerVersion::advertisesFpow());
            $this->assertTrue(CompilerVersion::advertisesNextafter());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('fpow'));
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('nextafter'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['fpow']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'fpow')
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'nextafter')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testLazyObjectFactoriesEnabledOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsLazyObjectFactories());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testOverrideAttributeClassAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesOverrideAttributeClass());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->classes['override']));
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
            $this->assertTrue(CompilerVersion::advertisesBcround());
            $this->assertFalse(
                \PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('bcmath')
            );

            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['bcadd']));
            $this->assertTrue(isset($runtime->vmContext->functions['bcround']));
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'bcadd')
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'bcround')
            );
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('bcround'));
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('bcadd'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStrIncrementCallableAndAdvertisedOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsStrIncrement());
            $this->assertTrue(CompilerVersion::advertisesStrIncrement());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('str_increment'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['str_increment']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'str_increment')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHttpLastResponseHeadersAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHttpLastResponseHeaders());
            $this->assertTrue(CompilerVersion::advertisesHttpLastResponseHeaders());
            foreach (['http_get_last_response_headers', 'get_last_response_headers', 'http_clear_last_response_headers'] as $fn) {
                $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised($fn));
            }

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['http_get_last_response_headers']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'http_get_last_response_headers')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStreamContextSetOptionsAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamContextSetOptions());
            $this->assertTrue(CompilerVersion::advertisesStreamContextSetOptions());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('stream_context_set_options'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['stream_context_set_options']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'stream_context_set_options')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testZendThreadIdAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsZendThreadId());
            $this->assertTrue(CompilerVersion::advertisesZendThreadId());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('zend_thread_id'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['zend_thread_id']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'zend_thread_id')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReadonlyCallableButNotAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReadonlyBuiltin());
            $this->assertFalse(CompilerVersion::advertisesReadonlyBuiltin());
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('readonly'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['readonly']));
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'readonly')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStrIncrementCallableButNotAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStrIncrement());
            $this->assertFalse(CompilerVersion::advertisesStrIncrement());
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('str_increment'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['str_increment']));
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'str_increment')
            );

            $internal = \PHPCompiler\ext\standard\VmReflection::internalFunctionNameList();
            $this->assertNotContains('str_increment', $internal);
            $this->assertNotContains('str_decrement', $internal);
            $this->assertContains('fpow', $internal);
            $this->assertContains('nextafter', $internal);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testGraphemeProfile84BuiltinsCallableButNotAdvertisedWithoutIntl(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGraphemeStrContains());
            $this->assertTrue(CompilerVersion::advertisesGraphemeStrContains());
            $this->assertTrue(CompilerVersion::supportsGraphemeStrimwidth());
            $this->assertTrue(CompilerVersion::advertisesGraphemeStrimwidth());
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('grapheme_str_contains'));
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('grapheme_strimwidth'));
            $this->assertFalse(
                \PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('intl')
            );

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['grapheme_str_contains']));
            $this->assertTrue(isset($ctx->functions['grapheme_strimwidth']));
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'grapheme_str_contains')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'grapheme_strimwidth')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'grapheme_strlen')
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
