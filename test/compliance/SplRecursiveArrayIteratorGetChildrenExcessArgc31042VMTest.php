<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: RecursiveArrayIterator::getChildren excess argc (#31042). */
final class SplRecursiveArrayIteratorGetChildrenExcessArgc31042VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'recursivearrayiterator_getchildren_excess_argc_31042.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/recursivearrayiterator_getchildren_excess_argc_31042.phpt',
            'recursivearrayiterator_getchildren_excess_argc_31042.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
