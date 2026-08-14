<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: WeakReference::get excess argc → ArgumentCountError (#30925). */
final class WeakrefGetExcessArgc30925JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_weakref_get_30925_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_weakref_get_30925_jit.phpt',
            'excess_argc_weakref_get_30925_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
