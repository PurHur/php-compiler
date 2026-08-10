<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: is_a/is_subclass_of(null $class) TypeError under strict_types (#29817). */
final class IsASubclassNullClassStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'is_a_subclass_null_class_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/is_a_subclass_null_class_strict.phpt',
            'is_a_subclass_null_class_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
