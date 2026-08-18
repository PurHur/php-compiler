<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: define('LIT') inside a function is silent on first call (#32039).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class DefineInsideFunction32039VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'define_inside_function_32039.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/define_inside_function_32039.phpt',
            'define_inside_function_32039.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
