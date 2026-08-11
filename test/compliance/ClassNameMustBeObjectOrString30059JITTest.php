<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: class operand of new / ::method / ::CONST must be string|object (#30059).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class ClassNameMustBeObjectOrString30059JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'class_name_must_be_object_or_string_30059.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_name_must_be_object_or_string_30059.phpt',
            'class_name_must_be_object_or_string_30059.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
