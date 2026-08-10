<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: date_diff TypeError param shape (#29861).
 *
 * @group llvm
 * @group jit
 */
final class DateDiffParamShapeJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_diff_param_shape_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_diff_param_shape_jit.phpt',
            'date_diff_param_shape_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
