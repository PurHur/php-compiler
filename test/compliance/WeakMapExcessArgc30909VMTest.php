<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: WeakMap ArrayAccess excess argc → ArgumentCountError (#30909). */
final class WeakMapExcessArgc30909VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_weakmap_30909.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_weakmap_30909.phpt',
            'excess_argc_weakmap_30909.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
