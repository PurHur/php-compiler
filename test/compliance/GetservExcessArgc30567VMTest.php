<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getservbyname/getservbyport ArgumentCountError wording (#30567). */
final class GetservExcessArgc30567VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_getserv_30567.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_getserv_30567.phpt',
            'excess_argc_getserv_30567.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
