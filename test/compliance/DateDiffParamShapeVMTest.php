<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: date_diff TypeError param shape (#29861). */
final class DateDiffParamShapeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_diff_param_shape.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_diff_param_shape.phpt',
            'date_diff_param_shape.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
