<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** get_debug_type() VM compliance (issue #3080). */
final class GetDebugTypeBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'get_debug_type_basic.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/get_debug_type_basic.phpt',
            'get_debug_type_basic.phpt'
        );
        yield 'get_debug_type_enum.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/get_debug_type_enum.phpt',
            'get_debug_type_enum.phpt'
        );
    }
}
