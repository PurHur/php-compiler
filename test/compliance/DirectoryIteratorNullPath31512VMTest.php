<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DirectoryIterator family null/empty path → Argument #1 ($directory) (#31512).
 */
final class DirectoryIteratorNullPath31512VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'directory_iterator_null_path_message.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/directory_iterator_null_path_message.phpt',
            'directory_iterator_null_path_message.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
