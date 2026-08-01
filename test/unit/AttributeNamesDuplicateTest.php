<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\AttributeNames;
use PHPUnit\Framework\TestCase;

/** @covers AttributeNames::assertNoDuplicates (#3718) */
final class AttributeNamesDuplicateTest extends TestCase
{
    public function testRejectsDuplicateAllowDynamicProperties(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Attribute "AllowDynamicProperties" must not be repeated');

        AttributeNames::assertNoDuplicates(['AllowDynamicProperties', 'AllowDynamicProperties']);
    }

    public function testAllowsDistinctAttributes(): void
    {
        AttributeNames::assertNoDuplicates(['AllowDynamicProperties', 'SensitiveParameter']);
        $this->addToAssertionCount(1);
    }

    public function testAllowsRepeatableUserAttributeDuplicates(): void
    {
        $registry = new \PHPCompiler\Compiler\AttributeClassRegistry();
        $entries = [
            new \PHPCompiler\Compiler\AttributeEntry('A'),
            new \PHPCompiler\Compiler\AttributeEntry('A'),
        ];
        $result = AttributeNames::validateDuplicates($entries, $registry);
        $this->assertCount(2, $result);
        $this->assertTrue($result[0]->isRepeated);
        $this->assertTrue($result[1]->isRepeated);
    }

    public function testRejectsAllowDynamicPropertiesOnParameter(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "AllowDynamicProperties" cannot target parameter (allowed targets: class)'
        );

        AttributeNames::assertAllowDynamicPropertiesClassTargetOnly(['AllowDynamicProperties'], 'parameter');
    }

    public function testRejectsAllowDynamicPropertiesOnMethod(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "AllowDynamicProperties" cannot target method (allowed targets: class)'
        );

        AttributeNames::assertAllowDynamicPropertiesClassTargetOnly(['AllowDynamicProperties'], 'method');
    }

    public function testRejectsAllowDynamicPropertiesOnFunction(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "AllowDynamicProperties" cannot target function (allowed targets: class)'
        );

        AttributeNames::assertAllowDynamicPropertiesClassTargetOnly(['AllowDynamicProperties'], 'function');
    }

    public function testRejectsAllowDynamicPropertiesOnReadonlyClass(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Cannot apply #[AllowDynamicProperties] to readonly class R'
        );

        AttributeNames::assertAllowDynamicPropertiesNotOnReadonlyClass(['AllowDynamicProperties'], 'R');
    }

    public function testRejectsAllowDynamicPropertiesOnEnum(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Cannot apply #[AllowDynamicProperties] to enum Bad'
            );

            AttributeNames::assertAllowDynamicPropertiesNotOnEnum(['AllowDynamicProperties'], 'Bad');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAllowsAllowDynamicPropertiesOnEnumOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            AttributeNames::assertAllowDynamicPropertiesNotOnEnum(['AllowDynamicProperties'], 'Bad');
            $this->addToAssertionCount(1);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectsOverrideOnClass(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
            $this->assertFalse(CompilerVersion::supportsOverridePropertyTarget());
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Attribute "Override" cannot target class (allowed targets: method)'
            );
            AttributeNames::assertOverrideMethodTargetOnly(['Override'], 'class');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectsOverrideOnParameter(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
            $this->assertFalse(CompilerVersion::supportsOverridePropertyTarget());
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Attribute "Override" cannot target parameter (allowed targets: method)'
            );
            AttributeNames::assertOverrideMethodTargetOnly(['\\Override'], 'parameter');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectsOverrideOnPropertyBefore85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsOverridePropertyTarget());
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Attribute "Override" cannot target property (allowed targets: method)'
            );
            AttributeNames::assertOverrideMethodTargetOnly(['Override'], 'property');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectsOverrideOnClassConstant(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Attribute "Override" cannot target class constant (allowed targets: method)'
            );
            AttributeNames::assertOverrideMethodTargetOnly(['Override'], 'class constant');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectsOverrideOnClassConstantAt85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsOverridePropertyTarget());
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Attribute "Override" cannot target class constant (allowed targets: method, property)'
            );
            AttributeNames::assertOverrideMethodTargetOnly(['Override'], 'class constant');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAllowsOverrideOnPropertyAt85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsOverridePropertyTarget());
            AttributeNames::assertOverrideMethodTargetOnly(['Override'], 'property');
            $this->addToAssertionCount(1);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRejectsCompileTimeOnMethod(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "CompileTime" cannot target method (allowed targets: class constant, constant)'
        );

        AttributeNames::assertCompileTimeConstTargetOnly(['CompileTime'], 'method');
    }

    public function testAllowsCompileTimeOnClassConstant(): void
    {
        AttributeNames::assertCompileTimeConstTargetOnly(['CompileTime'], 'class constant');
        $this->addToAssertionCount(1);
    }

    public function testRejectsSensitiveParameterOnFunction(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "SensitiveParameter" cannot target function (allowed targets: parameter)'
        );

        AttributeNames::assertSensitiveParameterParamTargetOnly(['\\SensitiveParameter'], 'function');
    }

    public function testAllowsSensitiveParameterOnParameter(): void
    {
        AttributeNames::assertSensitiveParameterParamTargetOnly(['\\SensitiveParameter'], 'parameter');
        $this->addToAssertionCount(1);
    }

    public function testRejectsSensitiveParameterOnProperty(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "SensitiveParameter" cannot target property (allowed targets: parameter)'
        );

        AttributeNames::assertSensitiveParameterParamTargetOnly(['\\SensitiveParameter'], 'property');
    }

    public function testRejectsReturnTypeWillChangeOnFunction(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "ReturnTypeWillChange" cannot target function (allowed targets: method)'
        );

        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly(['\\ReturnTypeWillChange'], 'function');
    }

    public function testRejectsReturnTypeWillChangeOnProperty(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "ReturnTypeWillChange" cannot target property (allowed targets: method)'
        );

        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly(['ReturnTypeWillChange'], 'property');
    }

    public function testAllowsReturnTypeWillChangeOnMethod(): void
    {
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly(['\\ReturnTypeWillChange'], 'method');
        $this->addToAssertionCount(1);
    }
}
