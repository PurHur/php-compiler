<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for token_get_all() PHP 8.4 T_*_SET / T_PROPERTY_C (#28130).
 */
final class TokenPrivateSet84VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'token_get_all_private_set_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_private_set_forward84.phpt',
            'token_get_all_private_set_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
