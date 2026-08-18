<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: integer subtraction overflow promotes to float (#32422).
 *
 * @group llvm
 */
final class IntSubOverflowPromote32422JITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
