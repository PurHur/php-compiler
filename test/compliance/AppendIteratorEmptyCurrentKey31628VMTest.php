<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: empty AppendIterator::current()/key() return null (#31628).
 */
final class AppendIteratorEmptyCurrentKey31628VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'appenditerator_empty_current_key_31628.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/appenditerator_empty_current_key_31628.phpt',
            'appenditerator_empty_current_key_31628.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
