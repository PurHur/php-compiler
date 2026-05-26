<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_merge() on string-key maps (#2287). */
final class ArrayMergeAssocVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_merge_assoc.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_merge_assoc.phpt',
            'array_merge_assoc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
