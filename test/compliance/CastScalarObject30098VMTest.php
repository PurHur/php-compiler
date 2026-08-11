<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: (object) scalar cast — stdClass.scalar property (#30098, zend_operators.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class CastScalarObject30098VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cast_scalar_object.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/cast_scalar_object.phpt',
            'cast_scalar_object.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
