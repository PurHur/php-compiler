<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: native-object unary +/- and object⊙int TypeError (#32477).
 */
final class ObjectNumericTypeError32477VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'object_numeric_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/object_numeric_typeerror.phpt',
            'object_numeric_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
