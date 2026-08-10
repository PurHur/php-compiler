<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTimeZone::listIdentifiers / timezone_identifiers_list(null) TypeError under strict_types (#29844).
 *
 * @group llvm
 * @group jit
 */
final class TimezoneListIdentifiersNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'timezone_listidentifiers_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/timezone_listidentifiers_null_strict_jit.phpt',
            'timezone_listidentifiers_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
