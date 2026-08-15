<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: preg_split/spl_autoload_register/iterator_to_array/count/get_mangled excess argc → ArgumentCountError (#30575). */
final class MiscExcessArgc30575VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_misc_logic_30575.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_misc_logic_30575.phpt',
            'excess_argc_misc_logic_30575.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
