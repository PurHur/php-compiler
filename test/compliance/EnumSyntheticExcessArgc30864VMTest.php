<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Enum cases/from/tryFrom excess argc → ArgumentCountError (#30864). */
final class EnumSyntheticExcessArgc30864VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_enum_synthetic_30864.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_enum_synthetic_30864.phpt',
            'excess_argc_enum_synthetic_30864.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
