<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: parse_ini_string/file null typed args under strict_types → TypeError (#31264).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ParseIniNullTypedArgsStrict31264VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'parse_ini_null_typed_args_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_null_typed_args_strict.phpt',
            'parse_ini_null_typed_args_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
