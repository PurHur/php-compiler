<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for static local return $n++ pre-increment value (#9418, #9375). */
final class StaticReturnPostincTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_local_return_postinc.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_local_return_postinc.phpt',
            'static_local_return_postinc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
