<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_detect_order(null) getter under strict_types (#29920, php-src mbstring.c). */
final class MbDetectOrderNullGetterJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_detect_order_null_getter_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_detect_order_null_getter_jit.phpt',
            'mb_detect_order_null_getter_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
