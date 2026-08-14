<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ReflectionClass::* $filter is ?int (#30897).
 */
final class ReflectionClassFilterNullableInt30897JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'reflection_class_filter_nullable_int_30897_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reflection_class_filter_nullable_int_30897.phpt',
            'reflection_class_filter_nullable_int_30897.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
