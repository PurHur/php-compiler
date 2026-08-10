<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: temporary write context Zend-shaped Fatal error (#29769). */
final class TempWriteCompileFatalShapeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'temp_array_append_compile_fatal_shape.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/write_context/temp_array_append_compile_fatal_shape.phpt',
            'temp_array_append_compile_fatal_shape.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
