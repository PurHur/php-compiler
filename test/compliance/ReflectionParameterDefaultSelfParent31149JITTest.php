<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionParameter::getDefaultValueConstantName() self::/parent:: spelling (#31149). */
final class ReflectionParameterDefaultSelfParent31149JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'reflection_parameter_default_self_parent_31149_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/reflection/reflection_parameter_default_self_parent_31149_jit.phpt',
            'reflection_parameter_default_self_parent_31149_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
