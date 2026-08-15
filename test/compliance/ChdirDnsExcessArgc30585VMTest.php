<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: chdir/gethostbyname/gethostbynamel ArgumentCountError wording (#30585). */
final class ChdirDnsExcessArgc30585VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_chdir_dns_30585.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_chdir_dns_30585.phpt',
            'excess_argc_chdir_dns_30585.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
