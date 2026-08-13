<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: date_isodate_set + DateTime(Immutable)::setISODate (#30748).
 *
 * @group llvm
 * @group jit
 */
final class DateIsodateSetJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_isodate_set_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_isodate_set_jit.phpt',
            'date_isodate_set_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
