<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: is_scalar / is_numeric / is_resource excess argc → ArgumentCountError (#30687). */
final class IsPredicatesExcessArgc30687VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_is_predicates_30687.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_is_predicates_30687.phpt',
            'excess_argc_is_predicates_30687.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
