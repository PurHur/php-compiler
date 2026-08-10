<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: nullable TypeError expected type prints ?T not T|null (#29960).
 *
 * Dedicated provider — same pattern as NullableTypeerrorQuestionMarkVMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class NullableTypeerrorQuestionMarkJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'nullable_typeerror_question_mark.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/nullable_typeerror_question_mark.phpt',
            'nullable_typeerror_question_mark.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
