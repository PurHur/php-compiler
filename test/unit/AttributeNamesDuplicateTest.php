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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Cannot apply #[AllowDynamicProperties] to enum Bad'
        );

        AttributeNames::assertAllowDynamicPropertiesNotOnEnum(['AllowDynamicProperties'], 'Bad');
    }

    public function testRejectsOverrideOnClass(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "Override" cannot target class (allowed targets: method, class constant, property)'
        );

        AttributeNames::assertOverrideMethodTargetOnly(['Override'], 'class');
    }

    public function testRejectsOverrideOnParameter(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "Override" cannot target parameter (allowed targets: method, class constant, property)'
        );

        AttributeNames::assertOverrideMethodTargetOnly(['\\Override'], 'parameter');
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
}
