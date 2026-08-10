<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: ReflectionAttribute::newInstance Error when args passed to ctor-less attribute (#29955).
 *
 * Dedicated provider — same pattern as ReflectionAttributeNewInstanceNoCtorArgs29955VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class ReflectionAttributeNewInstanceNoCtorArgs29955JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'reflection_attribute_newinstance_no_ctor_args_29955.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reflection_attribute_newinstance_no_ctor_args_29955.phpt',
            'reflection_attribute_newinstance_no_ctor_args_29955.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
