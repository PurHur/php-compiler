<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: exception from __get is catchable (#25911).
 */
final class MagicGetThrowCatchableComplianceTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'magic_get_throw_catchable.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/magic_get_throw_catchable.phpt',
            'magic_get_throw_catchable.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
