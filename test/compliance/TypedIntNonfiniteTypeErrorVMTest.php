<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: weak typed int rejects INF/NAN (#27925, zend_dval_to_lval_safe). */
final class TypedIntNonfiniteTypeErrorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_int_nonfinite_type_error.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_int_nonfinite_type_error.phpt',
            'typed_int_nonfinite_type_error.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
