<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: empty MultipleIterator::current()/key() → RuntimeException (#31625).
 */
final class MultipleIteratorEmptyCurrentKey31625VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'multipleiterator_empty_current_key_31625.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/multipleiterator_empty_current_key_31625.phpt',
            'multipleiterator_empty_current_key_31625.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
