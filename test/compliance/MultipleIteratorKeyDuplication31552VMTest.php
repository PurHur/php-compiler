<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: MultipleIterator::attachIterator duplicate info → InvalidArgumentException (#31552).
 */
final class MultipleIteratorKeyDuplication31552VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'multipleiterator_key_duplication.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/multipleiterator_key_duplication.phpt',
            'multipleiterator_key_duplication.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
