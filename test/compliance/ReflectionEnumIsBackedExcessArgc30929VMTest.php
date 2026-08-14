<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionEnum isBacked/getBackingType excess argc → ArgumentCountError (#30929). */
final class ReflectionEnumIsBackedExcessArgc30929VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_enum_isbacked_30929.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_enum_isbacked_30929.phpt',
            'excess_argc_reflection_enum_isbacked_30929.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
