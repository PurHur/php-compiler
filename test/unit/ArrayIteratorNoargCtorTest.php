<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** ArrayIterator no-arg constructor parity (#11792). */
final class ArrayIteratorNoargCtorTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $root = __DIR__.'/../compliance/cases/stdlib';
        foreach ([
            'arrayiterator_noarg_ctor.phpt',
            'arrayiterator_noarg_ctor_jit.phpt',
        ] as $file) {
            yield $file => self::parsePHPT($root.'/'.$file, $file);
        }
    }

    public function testAotFixtureExists(): void
    {
        $this->assertFileExists(__DIR__.'/../fixtures/aot/cases/arrayiterator_noarg_ctor.phpt');
    }
}
