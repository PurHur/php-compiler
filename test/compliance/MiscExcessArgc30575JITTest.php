<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: preg_split/spl_autoload_register/iterator_to_array/count/get_mangled excess argc → ArgumentCountError (#30575). */
final class MiscExcessArgc30575JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_misc_logic_30575_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_misc_logic_30575_jit.phpt',
            'excess_argc_misc_logic_30575_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
