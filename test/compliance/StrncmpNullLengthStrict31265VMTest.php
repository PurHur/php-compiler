<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: strncmp/strncasecmp(null $length) TypeError under strict_types (#31265). */
final class StrncmpNullLengthStrict31265VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strncmp_null_length_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strncmp_null_length_strict.phpt',
            'strncmp_null_length_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
