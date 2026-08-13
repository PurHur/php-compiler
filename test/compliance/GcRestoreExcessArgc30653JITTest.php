<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: gc_enabled / restore_*_handler excess argc → ArgumentCountError (#30653). */
final class GcRestoreExcessArgc30653JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_gc_restore_30653_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_gc_restore_30653_jit.phpt',
            'excess_argc_gc_restore_30653_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
