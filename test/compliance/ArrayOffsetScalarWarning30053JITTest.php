<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: PROFILE≥8.3 scalar dim-read Warning short form + true/false (#30053).
 */
require_once __DIR__.'/../BaseTest.php';

final class ArrayOffsetScalarWarning30053JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_offset_scalar_warning_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_offset_scalar_warning_84.phpt',
            'array_offset_scalar_warning_84.phpt'
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
