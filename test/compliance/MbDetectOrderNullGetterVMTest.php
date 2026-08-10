<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_detect_order(null) getter under strict_types (#29920, php-src mbstring.c). */
final class MbDetectOrderNullGetterVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_detect_order_null_getter.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_detect_order_null_getter.phpt',
            'mb_detect_order_null_getter.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
