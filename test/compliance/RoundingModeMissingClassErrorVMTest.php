<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Missing class on Class::CONST / RoundingMode::case → catchable Error (#28480).
 */
class RoundingModeMissingClassErrorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'language/rounding_mode_missing_class_error' => self::parsePHPT(
            __DIR__.'/cases/language/rounding_mode_missing_class_error.phpt',
            'rounding_mode_missing_class_error.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
