<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: function-static array dim ++ persists across calls (#32305). */
final class FunctionStaticArrayInc32305VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'function_static_array_inc.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/function_static_array_inc.phpt',
            'function_static_array_inc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
