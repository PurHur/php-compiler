<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: strftime/gmstrftime full E_DEPRECATED wording (#29321). */
final class StrftimeDeprecatedMessage29321VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strftime_deprecated_message_29321.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strftime_deprecated_message_29321.phpt',
            'strftime_deprecated_message_29321.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
