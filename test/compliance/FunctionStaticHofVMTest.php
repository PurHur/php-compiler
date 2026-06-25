<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: function static locals persist through higher-order callback (#11451). */
final class FunctionStaticHofVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'function_static_hof.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/function_static_hof.phpt',
            'function_static_hof.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
