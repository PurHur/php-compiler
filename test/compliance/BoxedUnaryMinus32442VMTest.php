<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: boxed unary minus stays int for numeric strings/longs (#32442).
 */
final class BoxedUnaryMinus32442VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'boxed_unary_minus.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/boxed_unary_minus.phpt',
            'boxed_unary_minus.phpt'
        );
    }
}
