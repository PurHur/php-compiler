<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: nullable TypeError expected type prints ?T not T|null (#29960).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class NullableTypeerrorQuestionMarkVMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
