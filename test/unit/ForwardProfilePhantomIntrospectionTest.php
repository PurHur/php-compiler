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

    public function testNoDiscardAttributeClassAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesNoDiscardAttributeClass());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->classes['nodiscard']));
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

    public function testGetObjectIdCallableAndAdvertisedOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsGetObjectId());
            $this->assertTrue(CompilerVersion::advertisesGetObjectId());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('get_object_id'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['get_object_id']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'get_object_id')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testGetObjectIdCallableAndAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGetObjectId());
            $this->assertTrue(CompilerVersion::advertisesGetObjectId());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('get_object_id'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['get_object_id']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'get_object_id')
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

    public function testReadonlyAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReadonlyBuiltin());
            $this->assertTrue(CompilerVersion::advertisesReadonlyBuiltin());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('readonly'));

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['readonly']));
            $this->assertTrue(
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

    public function testGeneratorToArrayWithheldOnPhp82Profile(): void
    {
        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('generator_to_array'));
            $runtime = new Runtime();
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'generator_to_array')
            );
            $this->assertFalse(isset($runtime->vmContext->functions['generator_to_array']));
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
        }
    }

    public function testReferenceProfilePhantomFunctionExistsForForwardBuiltins(): void
    {
        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            foreach ([
                'json_validate',
                'array_any',
                'fpow',
                'stream_supports',
                'class_uses_recursive',
            ] as $fn) {
                $this->assertFalse(
                    BuiltinIntrospectionPolicy::functionIsAdvertised($fn),
                    $fn.' must not be advertised on 8.4.0-dev reference profile'
                );
            }

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach ([
                'json_validate',
                'array_any',
                'fpow',
                'stream_supports',
                'class_uses_recursive',
            ] as $fn) {
                $this->assertFalse(
                    \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' function_exists must be false on reference profile'
                );
            }
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
        }
    }

    public function testReferenceProfileGraphemeBuiltinsNotCallableWithoutIntl(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsGraphemeStrContains());
            $this->assertFalse(CompilerVersion::supportsGraphemeStrimwidth());
            $this->assertFalse(CompilerVersion::supportsGraphemeForwardProfileCore());
            $this->assertFalse(
                \PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('intl')
            );

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach ([
                'grapheme_strlen',
                'grapheme_substr',
                'grapheme_strpos',
                'grapheme_extract',
                'grapheme_str_split',
                'grapheme_str_contains',
                'grapheme_strimwidth',
            ] as $fn) {
                $this->assertFalse(
                    BuiltinIntrospectionPolicy::functionIsAdvertised($fn),
                    $fn.' must not be advertised on reference profile'
                );
                $this->assertFalse(
                    isset($ctx->functions[$fn]),
                    $fn.' must not be registered on reference profile'
                );
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testGraphemeProfile84BuiltinsNotAdvertisedWithoutIntl(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGraphemeStrContains());
            $this->assertTrue(CompilerVersion::supportsGraphemeStrimwidth());
            $this->assertTrue(CompilerVersion::supportsGraphemeForwardProfileCore());
            $this->assertFalse(CompilerVersion::advertisesGraphemeForwardProfileCore());
            $this->assertFalse(
                \PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('intl')
            );
            foreach ([
                'grapheme_strlen',
                'grapheme_substr',
                'grapheme_strpos',
                'grapheme_extract',
                'grapheme_str_split',
                'grapheme_str_contains',
                'grapheme_strimwidth',
            ] as $fn) {
                $this->assertFalse(
                    BuiltinIntrospectionPolicy::functionIsAdvertised($fn),
                    $fn.' must not be advertised on forward 8.4 profile without intl'
                );
            }

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach ([
                'grapheme_strlen',
                'grapheme_substr',
                'grapheme_strpos',
                'grapheme_extract',
                'grapheme_str_split',
                'grapheme_str_contains',
                'grapheme_strimwidth',
            ] as $fn) {
                $this->assertFalse(
                    isset($ctx->functions[$fn]),
                    $fn.' must not be registered without ext/intl'
                );
                $this->assertFalse(
                    \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must not be visible on forward 8.4 profile without intl'
                );
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
