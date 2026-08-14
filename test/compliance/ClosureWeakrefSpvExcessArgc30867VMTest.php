<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Closure/WeakReference/SensitiveParameterValue excess argc → ArgumentCountError (#30867). */
final class ClosureWeakrefSpvExcessArgc30867VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_closure_weakref_spv_30867.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_closure_weakref_spv_30867.phpt',
            'excess_argc_closure_weakref_spv_30867.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
