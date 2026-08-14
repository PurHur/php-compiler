<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ArrayObject remaining method excess argc → ArgumentCountError (#30965). */
final class ArrayObjectExcessArgc30965VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'arrayobject_excess_argc.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/arrayobject_excess_argc.phpt',
            'arrayobject_excess_argc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
