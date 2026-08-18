<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: dim assign-op warns on missing keys then treats holes as null (#31991).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class DimAssignOpUndefinedArrayKey31991VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dim_assign_op_undefined_array_key_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/dim_assign_op_undefined_array_key_warning.phpt',
            'dim_assign_op_undefined_array_key_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
