<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: array unpack Error/TypeError appends ", <type> given" under PROFILE≥8.4 (#30055).
 */
require_once __DIR__.'/../BaseTest.php';

final class ArraySpreadNonTraversableGiven30055VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_spread_non_traversable_given_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_spread_non_traversable_given_84.phpt',
            'array_spread_non_traversable_given_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
