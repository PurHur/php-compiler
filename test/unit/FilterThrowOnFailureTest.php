<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\filter\BuiltinClasses;
use PHPCompiler\ext\filter\FilterConstants;
use PHPCompiler\ext\filter\VmFilter;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * FILTER_THROW_ON_FAILURE + Filter\* exceptions (#28131).
 *
 * @covers \PHPCompiler\ext\filter\VmFilter
 * @covers \PHPCompiler\ext\filter\BuiltinClasses
 */
final class FilterThrowOnFailureTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
    }

    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }

    public function testRegisteredConstantsAndClassesUnderProfile85(): void
    {
        $this->assertTrue(CompilerVersion::supportsFilterThrowOnFailure());
        $this->assertArrayHasKey('FILTER_THROW_ON_FAILURE', FilterConstants::registeredConstants());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertArrayHasKey(BuiltinClasses::CLASS_FILTER_EXCEPTION, $ctx->classes);
        $this->assertArrayHasKey(BuiltinClasses::CLASS_FILTER_FAILED_EXCEPTION, $ctx->classes);
    }

    public function testFilterVarThrowsFilterFailedException(): void
    {
        $value = new Variable();
        $value->string('nope');
        $options = new Variable();
        $options->int(VmFilter::FILTER_THROW_ON_FAILURE);

        try {
            VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_INT, $options);
            $this->fail('expected Filter\\FilterFailedException');
        } catch (\Filter\FilterFailedException $e) {
            $this->assertStringContainsString("filter int not satisfied by 'nope'", $e->getMessage());
        }
    }

    public function testFilterVarSuccessStillReturnsInt(): void
    {
        $value = new Variable();
        $value->string('12');
        $options = new Variable();
        $options->int(VmFilter::FILTER_THROW_ON_FAILURE);
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_INT, $options);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(12, $out->toInt());
    }

    public function testNullAndThrowCombinedIsValueError(): void
    {
        $value = new Variable();
        $value->string('12');
        $options = new Variable();
        $options->int(VmFilter::FILTER_NULL_ON_FAILURE | VmFilter::FILTER_THROW_ON_FAILURE);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            'filter_var(): Argument #3 ($options) cannot use both FILTER_NULL_ON_FAILURE and FILTER_THROW_ON_FAILURE'
        );
        VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_INT, $options);
    }

    public function testClassesAbsentUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertFalse(CompilerVersion::supportsFilterThrowOnFailure());
        $this->assertArrayNotHasKey('FILTER_THROW_ON_FAILURE', FilterConstants::registeredConstants());

        $runtime = new Runtime();
        $this->assertArrayNotHasKey(BuiltinClasses::CLASS_FILTER_EXCEPTION, $runtime->vmContext->classes);
        $this->assertArrayNotHasKey(BuiltinClasses::CLASS_FILTER_FAILED_EXCEPTION, $runtime->vmContext->classes);
    }
}
