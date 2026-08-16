<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: token_name(null) under strict_types → TypeError (#31407).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TokenNameNullStrict31407VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'token_name_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_name_null_strict.phpt',
            'token_name_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
