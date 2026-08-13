<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: is_scalar / is_numeric / is_resource excess argc → ArgumentCountError (#30687). */
final class IsPredicatesExcessArgc30687JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_is_predicates_30687_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_is_predicates_30687_jit.phpt',
            'excess_argc_is_predicates_30687_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
