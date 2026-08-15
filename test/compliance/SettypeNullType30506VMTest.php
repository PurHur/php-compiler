<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: settype(null) soft-null DEP+ValueError / strict TypeError (#30506). */
final class SettypeNullType30506VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'settype_null_type_30506.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/settype_null_type_30506.phpt',
            'settype_null_type_30506.phpt'
        );
        yield 'settype_null_type_strict_30506.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/settype_null_type_strict_30506.phpt',
            'settype_null_type_strict_30506.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
