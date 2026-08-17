<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: null default on non-nullable typed property suggests ?T (#31820).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TypedPropNullDefaultFatal31820VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_prop_null_default_fatal_31820.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_null_default_fatal_31820.phpt',
            'typed_prop_null_default_fatal_31820.phpt'
        );
        yield 'typed_prop_null_default_ok_nullable_31820.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_null_default_ok_nullable_31820.phpt',
            'typed_prop_null_default_ok_nullable_31820.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
