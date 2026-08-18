<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: integer subtraction overflow promotes to float (#32422).
 */
final class IntSubOverflowPromote32422VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'int_sub_overflow_promote.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/int_sub_overflow_promote.phpt',
            'int_sub_overflow_promote.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
