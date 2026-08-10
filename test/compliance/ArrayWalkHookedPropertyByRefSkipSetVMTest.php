<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_walk by-ref writeback on hooked properties skips set (#29703).
 *
 * Slash-free data-set name so --filter works (path-style VMTest names break the regex).
 */
final class ArrayWalkHookedPropertyByRefSkipSetVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'array_walk_hooked_property_byref_skip_set.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/language/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
