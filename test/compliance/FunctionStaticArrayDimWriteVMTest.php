<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: function static array dim/append/unset persist (#28038). */
final class FunctionStaticArrayDimWriteVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'function_static_array_dim_write.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/function_static_array_dim_write.phpt',
            'function_static_array_dim_write.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
