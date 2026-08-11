<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: token_get_all(null) TypeError under strict_types (#30257). */
final class TokenGetAllNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'token_get_all_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_null_strict.phpt',
            'token_get_all_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
