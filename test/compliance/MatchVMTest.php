<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for match expression subset (#2398, #2428). */
final class MatchVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'match_int.phpt',
                'match_identical.phpt',
                'match_literal.phpt',
                'match_default.phpt',
                'match_guard.phpt',
                'match_arm_assign.phpt',
                'match_unhandled.phpt',
                'match_enum_case.phpt',
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
