<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: untyped static property ++ in a method stores back (#32313). */
final class StaticPropMethodInc32313VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_prop_method_inc_32313.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_prop_method_inc_32313.phpt',
            'static_prop_method_inc_32313.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
