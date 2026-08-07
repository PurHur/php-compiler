<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CompilerVersion gates for PHP 8.3+ surface (#5697, #5212, #5993). */
final class CompilerVersionGateTest extends TestCase
{
    public function testVersionReports84Dev(): void
    {
        $this->assertSame('8.4.0-dev', CompilerVersion::VERSION);
    }

    public function testSupportsStrIncrementFalseOnDefault84DevProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStrIncrement());
    }

    public function testSupportsStrIncrementFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsHashContextDebugInfoFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHashContextDebugInfo());
    }

    public function testSupportsHashContextDebugInfoFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsHashContextDebugInfo());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsHashContextDebugInfoTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHashContextDebugInfo());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPdoConnectFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPdoConnect());
    }

    public function testSupportsPdoConnectFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsPdoConnect());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPdoConnectTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPdoConnect());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsStrIncrementTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesStrIncrementFalseOnDefault84DevProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesStrIncrement());
    }

    public function testAdvertisesStrIncrementFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::advertisesStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesStrIncrementTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::advertisesStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesStrIncrementTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsGetObjectIdFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGetObjectId());
    }

    public function testSupportsGetObjectIdTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsGetObjectId());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesGetObjectIdFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesGetObjectId());
    }

    public function testAdvertisesGetObjectIdTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::advertisesGetObjectId());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassHasFunctionsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassHasFunctions());
    }

    public function testSupportsClassHasFunctionsTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsClassHasFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPhp84ReflectionProbeBuiltinsFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFinalPropertiesFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsFinalProperties());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFinalPropertiesFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsFinalProperties());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFinalPropertiesTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsFinalProperties());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFinalPromotedPropertiesFalseOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsFinalPromotedProperties());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFinalPromotedPropertiesTrueOnForwardProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsFinalPromotedProperties());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #24894 */
    public function testSupportsStaticClassFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsStaticClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #24894 */
    public function testSupportsStaticClassFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsStaticClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #24894 */
    public function testSupportsStaticClassTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStaticClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #23038 */
    public function testSupportsNoDiscardAttributeFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsNoDiscardAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #23038 */
    public function testSupportsNoDiscardAttributeFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsNoDiscardAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #23038, #24946 */
    public function testSupportsNoDiscardAttributeFalseOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsNoDiscardAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPhp84ReflectionProbeBuiltinsFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassUsesRecursiveAlwaysFalse(): void
    {
        // php-src has class_uses only — class_uses_recursive is a phantom (#28365).
        $this->assertFalse(CompilerVersion::supportsClassUsesRecursive());
        $this->assertFalse(CompilerVersion::advertisesClassUsesRecursive());
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.3', '8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsClassUsesRecursive(), $profile);
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }

    /** Issue #24823 / re-#17863: default + PROFILE=8.2 withhold dynamic Class::{$expr}. */
    public function testSupportsDynamicClassConstFetchFalseOnReferenceAndProfile82(): void
    {
        $this->assertFalse(CompilerVersion::supportsDynamicClassConstFetch());

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDynamicClassConstFetch());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDynamicClassConstFetchTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsDynamicClassConstFetch());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReadonlyAnonymousClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyAnonymousClass());
    }

    public function testSupportsReadonlyAnonymousClassTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsReadonlyAnonymousClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReadonlyCloneReinitFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyCloneReinit());
    }

    public function testSupportsReadonlyCloneReinitTrueOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsReadonlyCloneReinit());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReadonlyCloneReinitFalseOnExplicitProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsReadonlyCloneReinit());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedFunctionStaticFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsTypedFunctionStatic());
    }

    public function testSupportsTypedFunctionStaticTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedFunctionStatic());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsArbitraryStaticVariableInitializersFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsArbitraryStaticVariableInitializers());
    }

    public function testSupportsArbitraryStaticVariableInitializersTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsArbitraryStaticVariableInitializers());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsProcGetStatusPendingSignalsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsProcGetStatusPendingSignals());
    }

    public function testSupportsProcGetStatusPendingSignalsTrueOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsProcGetStatusPendingSignals());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsProcGetStatusPendingSignalsTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsProcGetStatusPendingSignals());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMbStrPadFalseOnDefault84DevReference(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbStrPad());
    }

    public function testSupportsMbStrPadFalseOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsMbStrPad());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMbStrPadTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMbStrPad());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsHex2binStrictFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHex2binStrict());
    }

    public function testSupportsHex2binStrictTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHex2binStrict());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFpowFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsFpow());
    }

    public function testSupportsLazyObjectFactoriesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsLazyObjectFactories());
    }

    public function testSupportsReflectionClassPhp84ApisFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionClassPhp84Apis());
    }

    public function testSupportsReflectionPropertyPhp84RawValueApisFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyPhp84RawValueApis());
    }

    public function testSupportsReflectionPropertyPhp84RawValueApisTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyPhp84RawValueApis());
            $this->assertFalse(CompilerVersion::supportsReflectionPropertyGetMangledName());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyGetMangledNameFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyGetMangledName());
    }

    public function testSupportsReflectionPropertyGetMangledNameTrueOnForwardProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyGetMangledName());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionClassPhp84ApisTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionClassPhp84Apis());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsLazyObjectFactoriesTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsLazyObjectFactories());
            // Free-function createLazy* stay off on every profile (#28414).
            $this->assertFalse(CompilerVersion::supportsLazyObjectFreeFunctions());
            // Free-function class_has_lazy_object_* stay off on every profile (#28517).
            $this->assertFalse(CompilerVersion::supportsClassHasLazyObjectFreeFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsLazyObjectFreeFunctionsAlwaysFalse(): void
    {
        $this->assertFalse(CompilerVersion::supportsLazyObjectFreeFunctions());
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsLazyObjectFreeFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertFalse(CompilerVersion::supportsLazyObjectFreeFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassHasLazyObjectFreeFunctionsAlwaysFalse(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassHasLazyObjectFreeFunctions());
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsClassHasLazyObjectFreeFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertFalse(CompilerVersion::supportsClassHasLazyObjectFreeFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBcmathFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBcmath());
    }

    public function testSupportsBcmathTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBcmath());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBz2FalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBz2());
    }

    public function testSupportsBz2TrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBz2());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMsgpackFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsMsgpack());
    }

    public function testSupportsMsgpackTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMsgpack());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsSimdjsonFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsSimdjson());
    }

    public function testSupportsSimdjsonTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsSimdjson());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsYamlFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsYaml());
    }

    public function testSupportsYamlTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsYaml());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsRedisFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRedis());
    }

    public function testSupportsRarFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRar());
    }

    public function testSupportsRarTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsRar());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsImapFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsImap());
    }

    public function testSupportsImapTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsImap());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsEioFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsEio());
    }

    public function testSupportsEioTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsEio());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsRedisTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsRedis());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMemcachedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsMemcached());
    }

    public function testSupportsMemcachedTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMemcached());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsXmlrpcFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsXmlrpc());
    }

    public function testSupportsXmlrpcTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsXmlrpc());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBrotliFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBrotli());
    }

    public function testSupportsBrotliTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBrotli());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsNextafterFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsNextafter());
    }

    public function testSupportsNextafterTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsNextafter());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsRoundingModeEnumFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRoundingModeEnum());
    }

    public function testSupportsJsonValidateFalseOnDefault84DevReference(): void
    {
        $this->assertFalse(CompilerVersion::supportsJsonValidate());
    }

    public function testSupportsJsonValidateFalseOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsJsonValidate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsJsonValidateTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsJsonValidate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsSocketAtmarkFalseOnDefault84DevReference(): void
    {
        $this->assertFalse(CompilerVersion::supportsSocketAtmark());
        $this->assertFalse(CompilerVersion::advertisesSocketAtmark());
    }

    public function testSupportsSocketAtmarkFalseOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsSocketAtmark());
            $this->assertFalse(CompilerVersion::advertisesSocketAtmark());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsSocketAtmarkTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsSocketAtmark());
            $this->assertTrue(CompilerVersion::advertisesSocketAtmark());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsSocketShutConstantsFalseOnDefaultAnd84(): void
    {
        $this->assertFalse(CompilerVersion::supportsSocketShutConstants());
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsSocketShutConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsSocketShutConstantsTrueOn85Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsSocketShutConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testJsonEncodeUnitEnumValueErrorAlwaysFalse(): void
    {
        // Zend never ValueError for unit enums (#22681/#22688) — gate retired to always-false.
        $this->assertFalse(CompilerVersion::jsonEncodeUnitEnumValueError());
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::jsonEncodeUnitEnumValueError());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsStreamSupportsAlwaysFalse(): void
    {
        // php-src has stream_supports_lock only — stream_supports is a phantom (#28367).
        $this->assertFalse(CompilerVersion::supportsStreamSupports());
        $this->assertFalse(CompilerVersion::advertisesStreamSupports());
        $this->assertFalse(CompilerVersion::supportsStreamSupportReadWriteConstants());
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.3', '8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsStreamSupports(), $profile);
            $this->assertFalse(CompilerVersion::advertisesStreamSupports(), $profile);
            $this->assertFalse(CompilerVersion::supportsStreamSupportReadWriteConstants(), $profile);
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }

    public function testAdvertisesReflectionConstantClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesReflectionConstantClass());
    }

    public function testAdvertisesReflectionConstantClassFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesReflectionConstantClassTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantClass());
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantFileExtensionApis());
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantGetAttributes());
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantInNamespace());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesReflectionConstantClassTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantClass());
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantFileExtensionApis());
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantGetAttributes());
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantInNamespace());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesReflectionConstantFileExtensionApisOnProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantFileExtensionApis());
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantGetAttributes());
            $this->assertFalse(CompilerVersion::advertisesReflectionConstantInNamespace());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesReflectionConstantInNamespaceOnProfile86(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.6');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantInNamespace());
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantFileExtensionApis());
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantGetAttributes());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesOverrideAttributeClassFalseOnUnsetReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            // 8.4.0-dev + unset profile → Zend 8.2 phantom gate (#22142).
            $this->assertFalse(CompilerVersion::advertisesOverrideAttributeClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesOverrideAttributeClassTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesOverrideAttributeClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesEnumCasesAttributeClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesEnumCasesAttributeClass());
    }

    public function testAdvertisesEnumCasesAttributeClassTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesEnumCasesAttributeClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesDelayedTargetValidationAttributeClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesDelayedTargetValidationAttributeClass());
    }

    public function testAdvertisesDelayedTargetValidationAttributeClassFalseOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::advertisesDelayedTargetValidationAttributeClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesCompileTimeAttributeClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesCompileTimeAttributeClass());
    }

    public function testAdvertisesCompileTimeAttributeClassTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesCompileTimeAttributeClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClockGettimeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClockGettime());
    }

    public function testSupportsBuiltinStubEnumsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBuiltinStubEnums());
    }

    public function testSupportsGcStatusPhp84SchemaFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGcStatusPhp84Schema());
    }

    public function testSupportsGcStatusPhp84SchemaTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGcStatusPhp84Schema());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyAccessProbesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyAccessProbes());
    }

    public function testSupportsReflectionPropertyAccessProbesTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyAccessProbes());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyHookProbesFalseOnDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsReflectionPropertyHookProbes());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyHookProbesFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsReflectionPropertyHookProbes());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyHookProbesTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyHookProbes());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyReadableSettableTypeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyReadableSettableType());
    }

    public function testSupportsReflectionPropertyReadableSettableTypeTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyReadableSettableType());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyIsDynamicFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyIsDynamic());
    }

    public function testSupportsReflectionPropertyIsDynamicTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyIsDynamic());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionEnumUnitCaseIsDeprecatedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated());
    }

    public function testSupportsReflectionEnumUnitCaseIsDeprecatedTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionEnumCaseIsBackedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionEnumCaseIsBacked());
    }

    public function testSupportsReflectionEnumCaseIsBackedTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionEnumCaseIsBacked());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionClassConstantIsDeprecatedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionClassConstantIsDeprecated());
    }

    public function testSupportsReflectionClassConstantIsDeprecatedTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionClassConstantIsDeprecated());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionCreateFromFactoriesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionCreateFromFactories());
    }

    public function testSupportsReflectionCreateFromFactoriesTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionCreateFromFactories());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionEnumFromNameFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionEnumFromName());
    }

    public function testSupportsReflectionEnumFromNameTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionEnumFromName());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsHrtimeAsNumberFloatFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHrtimeAsNumberFloat());
    }

    public function testSupportsHrtimeAsNumberFloatFalseOnForwardProfile64Bit(): void
    {
        if (\PHP_INT_SIZE < 8) {
            $this->markTestSkipped('32-bit host only');
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsHrtimeAsNumberFloat());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstantsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassConstants());
    }

    public function testSupportsHeaderListFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHeaderList());
    }

    public function testSupportsArrayReplaceKeyFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsArrayReplaceKey());
    }

    public function testSupportsArrayReplaceKeyTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsArrayReplaceKey());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstantsTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsClassConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsHeaderListTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHeaderList());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsHttpLastResponseHeadersWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHttpLastResponseHeaders());
    }

    public function testSupportsHttpLastResponseHeadersTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHttpLastResponseHeaders());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsStreamContextSetOptionsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamContextSetOptions());
    }

    public function testSupportsStreamContextSetOptionsTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamContextSetOptions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPipeOperatorFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPipeOperator());
    }

    public function testSupportsPipeOperatorFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsPipeOperator());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPipeOperatorTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsPipeOperator());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsCastsInConstantExpressionsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsCastsInConstantExpressions());
    }

    public function testSupportsCastsInConstantExpressionsFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsCastsInConstantExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsCastsInConstantExpressionsTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsCastsInConstantExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClosuresInConstantExpressionsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClosuresInConstantExpressions());
    }

    public function testSupportsClosuresInConstantExpressionsFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsClosuresInConstantExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClosuresInConstantExpressionsTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsClosuresInConstantExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPhpBuildDateConstantFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPhpBuildDateConstant());
    }

    public function testSupportsPhpSbindirConstantFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPhpSbindirConstant());
    }

    public function testSupportsPhpSbindirConstantFalseWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertFalse(CompilerVersion::supportsPhpSbindirConstant());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPhpSbindirConstantTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPhpSbindirConstant());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPhpBuildDateConstantFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsPhpBuildDateConstant());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPhpBuildDateConstantTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsPhpBuildDateConstant());
            $stamp = CompilerVersion::phpBuildDateStamp();
            $this->assertNotFalse(
                \DateTimeImmutable::createFromFormat('M j Y H:i:s', $stamp),
                'PHP_BUILD_DATE stamp must parse as M j Y H:i:s'
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsNullArrayOffsetDeprecationFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsNullArrayOffsetDeprecation());
    }

    public function testSupportsNullArrayOffsetDeprecationFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsNullArrayOffsetDeprecation());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsNullArrayOffsetDeprecationTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsNullArrayOffsetDeprecation());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedIllegalContainerOffsetFalseOnUnsetReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsTypedIllegalContainerOffset());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedIllegalContainerOffsetFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsTypedIllegalContainerOffset());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedIllegalContainerOffsetTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedIllegalContainerOffset());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedIllegalContainerOffsetTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedIllegalContainerOffset());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDereferencableNewWithoutOuterParensFalseOnDefault84DevReference(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsDereferencableNewWithoutOuterParens());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDereferencableNewWithoutOuterParensFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDereferencableNewWithoutOuterParens());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDereferencableNewWithoutOuterParensTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDereferencableNewWithoutOuterParens());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsCloneWithSyntaxFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsCloneWithSyntax());
    }

    public function testSupportsCloneWithSyntaxFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            // Zend 8.4 parse-errors clone-with; only 8.5+ (#23877).
            $this->assertFalse(CompilerVersion::supportsCloneWithSyntax());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsCloneWithSyntaxTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsCloneWithSyntax());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsEnumCaseListFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsEnumCaseList());
    }

    public function testRejectsAllowDynamicPropertiesOnEnumFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::rejectsAllowDynamicPropertiesOnEnum());
    }

    public function testRejectsAllowDynamicPropertiesOnEnumTrueOnForwardProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::rejectsAllowDynamicPropertiesOnEnum());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectsAttributeOnNonConcreteClassLikeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::rejectsAttributeOnNonConcreteClassLike());
    }

    public function testRejectsAttributeOnNonConcreteClassLikeTrueOnForwardProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::rejectsAttributeOnNonConcreteClassLike());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsAttributeTargetConstantFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsAttributeTargetConstant());
    }

    public function testSupportsAttributeTargetConstantTrueOnForwardProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsAttributeTargetConstant());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #24819 — must stay false on unset PROFILE (not isForwardProfileAtLeast) */
    public function testSupportsAsymmetricVisibilityFalseOn84DevReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        try {
            $this->assertFalse(CompilerVersion::supportsAsymmetricVisibility());
            $this->assertFalse(CompilerVersion::supportsParenthesizedAsymmetricSetModifier());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsAsymmetricVisibilityFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsAsymmetricVisibility());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsAsymmetricVisibilityTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsAsymmetricVisibility());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Property hooks withheld on 8.4.0-dev reference profile — Zend 8.2 parity (#24818). */
    public function testSupportsPropertyHooksFalseOnDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsPropertyHooks());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPropertyHooksFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsPropertyHooks());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPropertyHooksTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPropertyHooks());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsGetDeclaredExcludeDeprecatedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGetDeclaredExcludeDeprecated());
    }

    /** php-src never shipped $exclude_deprecated — withhold on PROFILE=8.4/8.5 too (#27900). */
    public function testSupportsGetDeclaredExcludeDeprecatedFalseOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsGetDeclaredExcludeDeprecated());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertFalse(CompilerVersion::supportsGetDeclaredExcludeDeprecated());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsGetClassAllowStringAlwaysFalse(): void
    {
        $this->assertFalse(CompilerVersion::supportsGetClassAllowString());
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsGetClassAllowString());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsGetDefinedFunctionsExcludeDisabledTrueOnReferenceProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled());
    }

    public function testSupportsGetDefinedFunctionsExcludeDisabledTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDeprecatedAttributeRuntimeNoticesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDeprecatedAttributeRuntimeNotices());
    }

    public function testSupportsDeprecatedAttributeRuntimeNoticesTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDeprecatedAttributeRuntimeNotices());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDeprecatedTraitAttributeFalseOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsDeprecatedTraitAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDeprecatedTraitAttributeTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsDeprecatedTraitAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsExitFunctionFormFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
    }

    public function testSupportsExitFunctionFormTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsExitFunctionForm());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedTraitConstantsTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsTypedTraitConstants());
    }

    public function testSupportsTypedClassConstantsFalseOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            // 8.4.0-dev advertises phpversion() 8.2.31 — Zend 8.2 parse-errors typed class consts (#22705).
            $this->assertFalse(CompilerVersion::supportsTypedClassConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedClassConstantsFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsTypedClassConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedClassConstantsTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedClassConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstObjectExpressionsFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstObjectExpressionsFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstObjectExpressionsFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            // Zend 8.4 still rejects class-const `new` (#21493).
            $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstObjectExpressionsFalseWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsInterfaceTypedConstantsFalseOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            // Same withholding as typed class constants (#24917, re-#24809).
            $this->assertFalse(CompilerVersion::supportsInterfaceTypedConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsInterfaceTypedConstantsFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsInterfaceTypedConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsInterfaceTypedConstantsTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsInterfaceTypedConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsInterfaceTypedConstantsTracksTypedClassConstants(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach ([null, '8.2', '8.3', '8.4'] as $profile) {
            if (null === $profile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$profile);
            }
            $this->assertSame(
                CompilerVersion::supportsTypedClassConstants(),
                CompilerVersion::supportsInterfaceTypedConstants(),
                'interface typed-const gate must match class gate (profile='.($profile ?? 'unset').')'
            );
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }

    public function testSupportsOverrideAttributeFalseOnUnsetReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            // 8.4.0-dev + unset profile → Zend 8.2 reference (#22142, re-#19822).
            $this->assertFalse(CompilerVersion::supportsOverrideAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsOverrideAttributeFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsOverrideAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsOverrideAttributeTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsOverrideAttributeTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsOverridePropertyTargetFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsOverridePropertyTarget());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsOverridePropertyTargetTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsOverridePropertyTarget());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFinalGlobalTypedConstantsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsFinalGlobalTypedConstants());
    }

    public function testSupportsFinalGlobalTypedConstantsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsFinalGlobalTypedConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsGlobalTypedConstantsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGlobalTypedConstants());
    }

    public function testSupportsGlobalTypedConstantsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsGlobalTypedConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterStrIncrementOnDefault84DevProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['str_decrement']));
        $this->assertFalse(isset($ctx->functions['str_increment']));
    }

    public function testVmDoesNotRegisterStrIncrementOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['str_decrement']));
            $this->assertFalse(isset($ctx->functions['str_increment']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersStrIncrementOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['str_decrement']));
            $this->assertTrue(isset($ctx->functions['str_increment']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterClassHasFunctionsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['class_has_method', 'class_has_property', 'class_has_constant'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
    }

    public function testVmRegistersClassHasFunctionsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['class_has_method', 'class_has_property', 'class_has_constant'] as $fn) {
                $this->assertTrue(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbStrPadOnDefault84DevReference(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['mb_str_pad']));
    }

    public function testVmDoesNotRegisterGraphemeStrSplitOnReferenceOr82Profile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGraphemeStrSplit());
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['grapheme_str_split']));

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsGraphemeStrSplit());
            $runtime82 = new Runtime();
            $this->assertFalse(isset($runtime82->vmContext->functions['grapheme_str_split']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersGraphemeStrSplitOnForwardProfile84(): void
    {
        if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::icuAvailable()) {
            $this->markTestSkipped('ICU-backed ext/intl required for grapheme_str_split (#22340)');
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGraphemeStrSplit());
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['grapheme_str_split']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbStrPadOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['mb_str_pad']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbUcfirstLcfirstOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['mb_ucfirst', 'mb_lcfirst'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
    }

    public function testVmRegistersMbStrPadOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['mb_str_pad']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbUcfirstLcfirstOnPhp83Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['mb_ucfirst', 'mb_lcfirst'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $fn);
            }
            $this->assertTrue(isset($ctx->functions['mb_str_pad']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersMbUcfirstLcfirstOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['mb_ucfirst', 'mb_lcfirst'] as $fn) {
                $this->assertTrue(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbUcwordsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['mb_ucwords']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbUcwordsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['mb_ucwords']));
    }

    public function testVmDoesNotRegisterClockGettimeOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['clock_gettime']));
        $this->assertFalse(isset($ctx->classes['clockinterface']));
    }

    public function testSupportsDateTimeCreateFromTimestampFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDateTimeCreateFromTimestamp());
    }

    public function testVmDoesNotRegisterCreateFromTimestampOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $dt = $runtime->vmContext->classes['datetime'] ?? null;
        $dti = $runtime->vmContext->classes['datetimeimmutable'] ?? null;
        $this->assertNotNull($dt);
        $this->assertNotNull($dti);
        $this->assertFalse(isset($dt->methods['createfromtimestamp']));
        $this->assertFalse(isset($dti->methods['createfromtimestamp']));
    }

    public function testVmDoesNotRegisterCreateFromTimestampOnProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertFalse(CompilerVersion::supportsDateTimeCreateFromTimestamp());
            $runtime = new Runtime();
            $dt = $runtime->vmContext->classes['datetime'] ?? null;
            $dti = $runtime->vmContext->classes['datetimeimmutable'] ?? null;
            $this->assertNotNull($dt);
            $this->assertNotNull($dti);
            $this->assertFalse(isset($dt->methods['createfromtimestamp']));
            $this->assertFalse(isset($dti->methods['createfromtimestamp']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersCreateFromTimestampOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDateTimeCreateFromTimestamp());
            $runtime = new Runtime();
            $dt = $runtime->vmContext->classes['datetime'] ?? null;
            $dti = $runtime->vmContext->classes['datetimeimmutable'] ?? null;
            $this->assertNotNull($dt);
            $this->assertNotNull($dti);
            $this->assertTrue(isset($dt->methods['createfromtimestamp']));
            $this->assertTrue(isset($dti->methods['createfromtimestamp']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterRoundingModeOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['roundingmode']));
    }

    public function testVmDoesNotRegisterNextafterOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['nextafter']));
    }

    public function testVmRegistersNextafterOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['nextafter']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterFpowOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['fpow', 'fmin', 'fmax', 'fadd', 'fsub', 'fmul'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
    }

    public function testSupportsRandomIntervalBoundaryFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRandomIntervalBoundary());
    }

    public function testVmDoesNotRegisterRandomIntervalBoundaryOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['random\\intervalboundary']));
    }

    public function testVmRegistersRandomIntervalBoundaryOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->classes['random\\intervalboundary']));
            $entry = $runtime->vmContext->classes['random\\randomizer'];
            $this->assertTrue(isset($entry->methods['getfloat'], $entry->methods['nextfloat']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterJsonValidateOnDefault84DevReference(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['json_validate']));
    }

    public function testVmDoesNotRegisterJsonValidateOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['json_validate']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterSocketAtmarkOnDefault84DevReference(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['socket_atmark']));
    }

    public function testVmDoesNotRegisterSocketAtmarkOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['socket_atmark']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersSocketAtmarkOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['socket_atmark']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMbTrimFunctionsWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsMbTrimFunctions());
            $this->assertFalse(CompilerVersion::advertisesMbTrimFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMbTrimFunctionsWithheldOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsMbTrimFunctions());
            $this->assertFalse(CompilerVersion::advertisesMbTrimFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbTrimOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbTrimOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMbTrimFunctionsTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMbTrimFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersMbTrimOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
                $this->assertTrue(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMbUcwordsWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsMbUcwords());
            $this->assertFalse(CompilerVersion::advertisesMbUcwords());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterStreamContextSetOptionsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['stream_context_set_options']));
        $this->assertTrue(isset($ctx->functions['stream_context_get_options']));
    }

    public function testSupportsStreamContextSetOptionsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamContextSetOptions());
    }

    public function testSupportsStreamContextSetOptionsTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamContextSetOptions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersStreamContextSetOptionsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['stream_context_set_options']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterClassConstantsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['class_constants']));
    }

    public function testVmDoesNotRegisterHeaderListOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['header_list']));
        $this->assertTrue(isset($ctx->functions['header']));
    }

    public function testVmRegistersReflectionPropertyAccessProbesOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $rp = $runtime->vmContext->classes['reflectionproperty'] ?? null;
            $this->assertNotNull($rp);
            $this->assertTrue(isset($rp->methods['isreadable']));
            $this->assertTrue(isset($rp->methods['iswritable']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterReflectionPropertyHookProbesOnDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $rp = $runtime->vmContext->classes['reflectionproperty'] ?? null;
            $this->assertNotNull($rp);
            $this->assertFalse(isset($rp->methods['isabstract']));
            $this->assertFalse(isset($rp->methods['isvirtual']));
            $this->assertFalse(isset($rp->methods['gethooks']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersReflectionPropertyHookProbesWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $rp = $runtime->vmContext->classes['reflectionproperty'] ?? null;
            $this->assertNotNull($rp);
            $this->assertTrue(isset($rp->methods['isabstract']));
            $this->assertTrue(isset($rp->methods['isvirtual']));
            $this->assertTrue(isset($rp->methods['gethooks']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterReflectionPropertyHookProbesWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $rp = $runtime->vmContext->classes['reflectionproperty'] ?? null;
            $this->assertNotNull($rp);
            $this->assertFalse(isset($rp->methods['isabstract']));
            $this->assertFalse(isset($rp->methods['isvirtual']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterArrayReplaceKeyOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['array_replace_key']));
        $this->assertTrue(isset($runtime->vmContext->functions['array_replace']));
    }

    public function testVmRegistersForwardProfileBuiltinsWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['array_replace_key']));
            $this->assertTrue(isset($ctx->functions['class_constants']));
            $this->assertTrue(isset($ctx->functions['header_list']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClosureGetCurrentFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClosureGetCurrent());
    }

    public function testSupportsClosureGetCurrentFalseOn84ForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsClosureGetCurrent());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClosureGetCurrentTrueOn85ForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsClosureGetCurrent());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #27594 */
    public function testSupportsSqlite3Php85ApisFalseOn84TrueOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsSqlite3Php85Apis());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsSqlite3Php85Apis());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClosureFromStaticFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClosureFromStatic());
    }

    public function testSupportsClosureFromStaticFalseOn84And85Profiles(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(
                CompilerVersion::supportsClosureFromStatic(),
                'fromStatic withheld on PROFILE='.$profile.' (#22583)'
            );
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }

    public function testVmDoesNotRegisterClosureFromStaticOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $closure = $runtime->vmContext->classes['closure'] ?? null;
        $this->assertNotNull($closure);
        $this->assertFalse(isset($closure->methods['fromstatic']));
    }

    public function testVmDoesNotRegisterClosureFromStaticOn84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $closure = $runtime->vmContext->classes['closure'] ?? null;
            $this->assertNotNull($closure);
            $this->assertFalse(isset($closure->methods['fromstatic']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClosureGetUsedVariablesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClosureGetUsedVariables());
    }

    public function testSupportsClosureGetUsedVariablesFalseOn84And85Profiles(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(
                CompilerVersion::supportsClosureGetUsedVariables(),
                'getUsedVariables withheld on PROFILE='.$profile.' (#22583)'
            );
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }

    public function testVmDoesNotRegisterClosureGetUsedVariablesOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $closure = $runtime->vmContext->classes['closure'] ?? null;
        $this->assertNotNull($closure);
        $this->assertFalse(isset($closure->methods['getusedvariables']));
    }

    public function testVmDoesNotRegisterClosureGetUsedVariablesOn84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $closure = $runtime->vmContext->classes['closure'] ?? null;
            $this->assertNotNull($closure);
            $this->assertFalse(isset($closure->methods['getusedvariables']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBareRethrowFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsBareRethrow());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBareRethrowFalseOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsBareRethrow());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBareRethrowTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBareRethrow());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterClosureGetCurrentOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $closure = $runtime->vmContext->classes['closure'] ?? null;
        $this->assertNotNull($closure);
        $this->assertFalse(isset($closure->methods['getcurrent']));
    }

    public function testVmDoesNotRegisterClosureGetCurrentOn84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $closure = $runtime->vmContext->classes['closure'] ?? null;
            $this->assertNotNull($closure);
            $this->assertFalse(isset($closure->methods['getcurrent']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersClosureGetCurrentOn85ForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $closure = $runtime->vmContext->classes['closure'] ?? null;
            $this->assertNotNull($closure);
            $this->assertTrue(isset($closure->methods['getcurrent']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeContainsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeContains());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeContainsOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeContains());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeContainsFalseOn84DevDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeContains());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomNodeContainsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $this->assertTrue(isset($node->methods['contains']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeCompareDocumentPositionFalseOn84DevDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeCompareDocumentPosition());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeCompareDocumentPositionFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeCompareDocumentPosition());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeCompareDocumentPositionOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeCompareDocumentPosition());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmWithholdsDomNodeCompareDocumentPositionOnDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $this->assertFalse(isset($node->methods['comparedocumentposition']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomNodeCompareDocumentPositionOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $this->assertTrue(isset($node->methods['comparedocumentposition']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeGetRootNodeOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeGetRootNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeIsConnectedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeIsConnected());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeIsConnectedOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeIsConnected());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomNodeIsConnectedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $names = [];
            foreach ($node->properties as $prop) {
                $names[] = strtolower($prop->name);
            }
            $this->assertContains('isconnected', $names);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeGetRootNodeOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeGetRootNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomNodeGetRootNodeOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $this->assertTrue(isset($node->methods['getrootnode']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentHtmlOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsDomElementInsertAdjacentHtml());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentHtmlWithheldOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsDomElementInsertAdjacentHtml());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementGetElementsByClassNameOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsDomElementGetElementsByClassName());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementGetElementsByClassNameWithheldOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsDomElementGetElementsByClassName());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeIsEqualNodeOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeIsEqualNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeReplaceChildrenOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeReplaceChildren());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeReplaceChildrenOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomNodeReplaceChildren());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomNodeReplaceChildrenOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $this->assertTrue(isset($node->methods['replacechildren']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentHtmlOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomElementInsertAdjacentHtml());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomElementInsertAdjacentHtmlOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $element = $runtime->vmContext->classes['domelement'] ?? null;
            $this->assertNotNull($element);
            $this->assertTrue(isset($element->methods['insertadjacenthtml']));
            $living = $runtime->vmContext->classes['dom\\element'] ?? null;
            $this->assertNotNull($living);
            $this->assertTrue(isset($living->methods['insertadjacenthtml']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmWithholdsDomElementInsertAdjacentHtmlOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $element = $runtime->vmContext->classes['domelement'] ?? null;
            $this->assertNotNull($element);
            $this->assertFalse(isset($element->methods['insertadjacenthtml']));
            $living = $runtime->vmContext->classes['dom\\element'] ?? null;
            $this->assertNotNull($living);
            $this->assertFalse(isset($living->methods['insertadjacenthtml']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomElementGetElementsByClassNameOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $living = $runtime->vmContext->classes['dom\\element'] ?? null;
            $this->assertNotNull($living);
            $this->assertTrue(isset($living->methods['getelementsbyclassname']));
            $document = $runtime->vmContext->classes['dom\\document'] ?? null;
            $this->assertNotNull($document);
            $this->assertTrue(isset($document->methods['getelementsbyclassname']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmWithholdsDomElementGetElementsByClassNameOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $living = $runtime->vmContext->classes['dom\\element'] ?? null;
            $this->assertNotNull($living);
            $this->assertFalse(isset($living->methods['getelementsbyclassname']));
            $document = $runtime->vmContext->classes['dom\\document'] ?? null;
            $this->assertNotNull($document);
            $this->assertFalse(isset($document->methods['getelementsbyclassname']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentElementOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomElementInsertAdjacentElement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentElementOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomElementInsertAdjacentElement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomElementInsertAdjacentElementOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $element = $runtime->vmContext->classes['domelement'] ?? null;
            $this->assertNotNull($element);
            $this->assertTrue(isset($element->methods['insertadjacentelement']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentTextOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomElementInsertAdjacentText());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentTextOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomElementInsertAdjacentText());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomElementInsertAdjacentTextOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $element = $runtime->vmContext->classes['domelement'] ?? null;
            $this->assertNotNull($element);
            $this->assertTrue(isset($element->methods['insertadjacenttext']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementToggleAttributeOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomElementToggleAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementToggleAttributeOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomElementToggleAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementToggleAttributeFalseOn84DevDefaultProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsDomElementToggleAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomDocumentAdoptNodeFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsDomDocumentAdoptNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomDocumentAdoptNodeFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDomDocumentAdoptNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomDocumentAdoptNodeTrueOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsDomDocumentAdoptNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomDocumentAdoptNodeTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomDocumentAdoptNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersDomElementToggleAttributeOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $element = $runtime->vmContext->classes['domelement'] ?? null;
            $this->assertNotNull($element);
            $this->assertTrue(isset($element->methods['toggleattribute']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionFunctionGetNamedArgumentsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionFunctionGetNamedArguments());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionFunctionGetNamedArgumentsOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionFunctionGetNamedArguments());
    }

    public function testVmRegistersReflectionFunctionGetNamedArgumentsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $rf = $runtime->vmContext->classes['reflectionfunction'] ?? null;
            $rm = $runtime->vmContext->classes['reflectionmethod'] ?? null;
            $this->assertNotNull($rf);
            $this->assertNotNull($rm);
            $this->assertTrue(isset($rf->methods['getnamedarguments']));
            $this->assertTrue(isset($rm->methods['getnamedarguments']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #26745 — createFromDate* withheld on unset PROFILE (Zend 8.2) */
    public function testSupportsIntlGregorianCreateFromDateFalseOnDefault84DevProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsIntlGregorianCreateFromDate());
    }

    public function testSupportsIntlGregorianCreateFromDateFalseOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsIntlGregorianCreateFromDate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsIntlGregorianCreateFromDateTrueOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsIntlGregorianCreateFromDate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsIntlGregorianCreateFromDateTrueOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsIntlGregorianCreateFromDate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
