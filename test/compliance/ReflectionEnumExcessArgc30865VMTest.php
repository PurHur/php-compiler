<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionEnum getCases/hasCase/getCase excess argc → ArgumentCountError (#30865). */
final class ReflectionEnumExcessArgc30865VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_enum_30865.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_enum_30865.phpt',
            'excess_argc_reflection_enum_30865.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
