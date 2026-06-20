<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: switch enum↔scalar case labels must not match via backing compare (#9857, zend_execute.c). */
final class SwitchEnumTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'switch_enum_scalar.phpt',
                'switch_enum_scalar_case.phpt',
                'switch_enum_scalar_subject.phpt',
                'switch_enum_case_scalar.phpt',
                'switch_enum_scalar_no_match.phpt',
            ] as $file
        ) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
