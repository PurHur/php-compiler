<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPCompiler\VM\ReflectionSupport;
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
            $this->assertFalse(CompilerVersion::supportsIeeeFloatOpPhantoms());
            $this->assertFalse(CompilerVersion::advertisesIeeeFloatOpPhantoms());
            $this->assertFalse(CompilerVersion::supportsNextafter());
            $this->assertFalse(CompilerVersion::advertisesNextafter());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('fpow'));
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('nextafter'));
            foreach (['fmin', 'fmax', 'fadd', 'fsub', 'fmul'] as $fn) {
                $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised($fn), $fn);
            }

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['fpow']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'fpow')
            );
            $this->assertFalse(
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
            $this->assertFalse(CompilerVersion::supportsLazyObjectFreeFunctions());
            $this->assertFalse(CompilerVersion::supportsClassHasLazyObjectFreeFunctions());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['createlazyghost']));
            $this->assertFalse(isset($ctx->functions['createlazyproxy']));
            $this->assertFalse(isset($ctx->functions['class_has_lazy_object_initializer']));
            $this->assertFalse(isset($ctx->functions['class_has_lazy_object_uninitializer']));
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'createLazyGhost')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'createLazyProxy')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'class_has_lazy_object_initializer')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'class_has_lazy_object_uninitializer')
            );
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['newlazyghost']));
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['newlazyproxy']));
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['isuninitializedlazyobject']));
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['getlazyinitializer']));
            // ReflectionParameter::isSensitive* are phantoms vs php-src (#28528).
            $this->assertFalse(CompilerVersion::supportsReflectionParameterIsSensitiveParameter());
            $this->assertFalse(isset($ctx->classes['reflectionparameter']->methods['issensitive']));
            $this->assertFalse(isset($ctx->classes['reflectionparameter']->methods['issensitiveparameter']));
            // ReflectionParameter/Property::isDeprecated are phantoms vs php-src (#28529).
            $this->assertFalse(CompilerVersion::supportsReflectionPropertyParameterIsDeprecated());
            $this->assertFalse(isset($ctx->classes['reflectionparameter']->methods['isdeprecated']));
            $this->assertFalse(isset($ctx->classes['reflectionproperty']->methods['isdeprecated']));
            $this->assertTrue(isset($ctx->classes['reflectionfunction']->methods['isdeprecated']));
            $this->assertTrue(isset($ctx->classes['reflectionclassconstant']->methods['isdeprecated']));
            // ReflectionProperty::getReadableType is a phantom; getSettableType is real (#28532).
            $this->assertFalse(CompilerVersion::supportsReflectionPropertyGetReadableType());
            $this->assertFalse(isset($ctx->classes['reflectionproperty']->methods['getreadabletype']));
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyReadableSettableType());
            $this->assertTrue(isset($ctx->classes['reflectionproperty']->methods['getsettabletype']));
            // Free-function isAnonymousClass() is a phantom; ReflectionClass::isAnonymous remains (#28616).
            $this->assertFalse(CompilerVersion::supportsIsAnonymousClass());
            $this->assertFalse(CompilerVersion::advertisesIsAnonymousClass());
            $this->assertFalse(isset($ctx->functions['isanonymousclass']));
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['isanonymous']));
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

    public function testNoDiscardAttributeClassNotAdvertisedOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::advertisesNoDiscardAttributeClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testNoDiscardAttributeClassAdvertisedOnForwardProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
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

    public function testBcmathCallableAndAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBcmath());
            $this->assertTrue(CompilerVersion::advertisesBcmath());
            $this->assertTrue(CompilerVersion::advertisesBcround());

            $runtime = new Runtime();
            $this->assertTrue(
                \PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('bcmath')
            );
            $this->assertTrue(isset($runtime->vmContext->functions['bcadd']));
            $this->assertTrue(isset($runtime->vmContext->functions['bcround']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'bcadd')
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'bcround')
            );
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('bcround'));
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('bcadd'));
            $this->assertTrue(isset($runtime->vmContext->classes['bcmath\\number']));
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

    public function testGetObjectIdAbsentOnForwardProfiles(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.3', '8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsGetObjectId(), $profile);
            $this->assertFalse(CompilerVersion::advertisesGetObjectId(), $profile);
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('get_object_id'), $profile);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['get_object_id']), $profile);
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

    public function testHttpLastResponseHeadersAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHttpLastResponseHeaders());
            $this->assertTrue(CompilerVersion::advertisesHttpLastResponseHeaders());
            foreach (['http_get_last_response_headers', 'http_clear_last_response_headers'] as $fn) {
                $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised($fn));
            }
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('get_last_response_headers'));
            $this->assertFalse(CompilerVersion::supportsGetLastResponseHeadersAlias());
            $this->assertFalse(CompilerVersion::advertisesGetLastResponseHeadersAlias());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['http_get_last_response_headers']));
            $this->assertFalse(isset($ctx->functions['get_last_response_headers']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'http_get_last_response_headers')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'get_last_response_headers')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStreamContextSetOptionsAdvertisedOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
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

    public function testStrIncrementCallableAndAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
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

            $internal = \PHPCompiler\ext\standard\VmReflection::internalFunctionNameList();
            $this->assertContains('str_increment', $internal);
            $this->assertContains('str_decrement', $internal);
            $this->assertContains('fpow', $internal);
            $this->assertNotContains('nextafter', $internal);
            foreach (['fmin', 'fmax', 'fadd', 'fsub', 'fmul'] as $fn) {
                $this->assertNotContains($fn, $internal, $fn);
            }
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

    /** Issue #28367: stream_supports remains absent under PROFILE≥8.4 (php-src has lock only). */
    public function testStreamSupportsPhantomAbsentOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsStreamSupports());
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('stream_supports'));
            $runtime = new Runtime();
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'stream_supports')
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($runtime->vmContext, 'stream_supports_lock')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReferenceProfileGraphemeBuiltinsNotCallableWithoutIntl(): void
    {
        if (\extension_loaded('intl')) {
            $this->markTestSkipped('host php-intl advertises grapheme_* (#22691)');
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsGraphemeStrContains());
            $this->assertFalse(CompilerVersion::supportsGraphemeStrimwidth());
            $this->assertFalse(CompilerVersion::supportsGraphemeStrSplit());
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
        if (\extension_loaded('intl')) {
            $this->markTestSkipped('host php-intl advertises grapheme_* (#22691)');
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGraphemeStrContains());
            $this->assertTrue(CompilerVersion::supportsGraphemeStrimwidth());
            $this->assertTrue(CompilerVersion::supportsGraphemeStrSplit());
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

    /** PHP 8.4 exit-as-function: function_exists/is_callable see exit/die (#20575, re-#6975). */
    public function testExitDieVisibleToFunctionExistsOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsExitFunctionForm());
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::isVisibleToFunctionExists('exit')
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::isVisibleToFunctionExists('die')
            );

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['exit']));
            $this->assertTrue(isset($ctx->functions['die']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'exit')
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'die')
            );
            // Still constructs forever:
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'eval')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, '__halt_compiler')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Reference / 8.2 profile: exit/die stay hidden from function_exists (#14738). */
    public function testExitDieHiddenFromFunctionExistsOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::isVisibleToFunctionExists('exit')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::isVisibleToFunctionExists('die')
            );

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'exit')
            );
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'die')
            );
            // is_callable must match function_exists on PROFILE=8.2 (#25421).
            foreach (['exit', 'die'] as $name) {
                $v = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_STRING);
                $v->string($name);
                $this->assertFalse(
                    \PHPCompiler\ext\standard\VmCallable::isCallable($ctx, $v),
                    $name
                );
            }

            // ReflectionFunction must match function_exists — Zend ReflectionException (#23687).
            foreach (['exit', 'die'] as $name) {
                try {
                    ReflectionSupport::resolveFunctionForReflection($ctx, $name);
                    $this->fail('expected ReflectionException for '.$name);
                } catch (\ReflectionException $e) {
                    $this->assertSame('Function '.$name.'() does not exist', $e->getMessage());
                }
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** PHP 8.4 profile: ReflectionFunction('exit'|'die') resolves with Zend status param (#23687). */
    public function testExitDieReflectionVisibleOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsExitFunctionForm());
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['exit', 'die'] as $name) {
                $func = ReflectionSupport::resolveFunctionForReflection($ctx, $name);
                $this->assertNotNull($func);
                $this->assertTrue(
                    \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, $name)
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

    /** Issue #26742: PHP 8.4 pcntl_* withheld on default 8.4.0-dev reference (Zend 8.2). */
    public function testPhp84PcntlApisWithheldOnDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsPhp84PcntlApis());
            $this->assertFalse(CompilerVersion::advertisesPhp84PcntlApis());
            foreach ([
                'pcntl_getcpu',
                'pcntl_getcpuaffinity',
                'pcntl_setcpuaffinity',
                'pcntl_setns',
                'pcntl_waitid',
            ] as $fn) {
                $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised($fn));
            }

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach ([
                'pcntl_getcpu',
                'pcntl_getcpuaffinity',
                'pcntl_setcpuaffinity',
                'pcntl_setns',
                'pcntl_waitid',
            ] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]));
                $this->assertFalse(
                    \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, $fn)
                );
            }
            $this->assertTrue(isset($ctx->functions['pcntl_fork']));
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'pcntl_fork')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #26742: PHP 8.4 pcntl_* advertised on PROFILE=8.4. */
    public function testPhp84PcntlApisAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPhp84PcntlApis());
            $this->assertTrue(CompilerVersion::advertisesPhp84PcntlApis());
            foreach ([
                'pcntl_getcpu',
                'pcntl_getcpuaffinity',
                'pcntl_setcpuaffinity',
                'pcntl_setns',
                'pcntl_waitid',
            ] as $fn) {
                $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised($fn));
            }

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach ([
                'pcntl_getcpu',
                'pcntl_getcpuaffinity',
                'pcntl_setcpuaffinity',
                'pcntl_setns',
                'pcntl_waitid',
            ] as $fn) {
                $this->assertTrue(isset($ctx->functions[$fn]));
                $this->assertTrue(
                    \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, $fn)
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
