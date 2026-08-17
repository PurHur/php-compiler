<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: deg2rad-family Reflection $num + Zend named params (#27795).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class MathNumNamed27795VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'math_num_named_27795.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/math_num_named_27795.phpt',
            'math_num_named_27795.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
