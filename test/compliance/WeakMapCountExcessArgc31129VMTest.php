<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: WeakMap::count() excess argc → ArgumentCountError (#31129). */
final class WeakMapCountExcessArgc31129VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_weakmap_count_31129.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_weakmap_count_31129.phpt',
            'excess_argc_weakmap_count_31129.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
