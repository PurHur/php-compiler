<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DirectoryIterator family missing path → UnexpectedValueException (#31506).
 */
final class DirectoryIteratorMissing31506VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'directory_iterator_missing_unexpected.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/directory_iterator_missing_unexpected.phpt',
            'directory_iterator_missing_unexpected.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
