<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for standalone `true`/`false` type parameters (#4784). */
final class StandaloneTrueFalseTypeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'standalone_true_false_type.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/standalone_true_false_type.phpt',
            'standalone_true_false_type.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
