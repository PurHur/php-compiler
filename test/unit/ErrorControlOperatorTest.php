<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** `@` error-control operator VM smoke (issue #3546). */
final class ErrorControlOperatorTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'error_control_operator.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/error_control_operator.phpt',
            'error_control_operator.phpt'
        );
    }
}
