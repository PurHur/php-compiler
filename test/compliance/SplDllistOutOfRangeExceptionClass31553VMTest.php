<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SplDoublyLinkedList OOB offset* → OutOfRangeException (#31553).
 */
final class SplDllistOutOfRangeExceptionClass31553VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spldllist_outofrange_exception_class.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spldllist_outofrange_exception_class.phpt',
            'spldllist_outofrange_exception_class.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
