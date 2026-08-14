<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: WeakReference::get excess argc → ArgumentCountError (#30925). */
final class WeakrefGetExcessArgc30925VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_weakref_get_30925.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_weakref_get_30925.phpt',
            'excess_argc_weakref_get_30925.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
