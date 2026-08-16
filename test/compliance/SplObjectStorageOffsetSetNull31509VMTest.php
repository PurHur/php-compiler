<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SplObjectStorage::offsetSet(null)/dim TypeError cites offsetSet (#31509).
 */
final class SplObjectStorageOffsetSetNull31509VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splobjectstorage_offsetset_null_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splobjectstorage_offsetset_null_typeerror.phpt',
            'splobjectstorage_offsetset_null_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
