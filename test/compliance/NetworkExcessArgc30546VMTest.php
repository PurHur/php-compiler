<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: network/DNS ArgumentCountError wording (#30546). */
final class NetworkExcessArgc30546VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_network_30546.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_network_30546.phpt',
            'excess_argc_network_30546.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
