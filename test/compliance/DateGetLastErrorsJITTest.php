<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: date_get_last_errors + DateTime::getLastErrors (#30749).
 *
 * @group llvm
 * @group jit
 */
final class DateGetLastErrorsJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_get_last_errors_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_get_last_errors_jit.phpt',
            'date_get_last_errors_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
