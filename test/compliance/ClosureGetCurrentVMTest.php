<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for Closure::getCurrent() (#13981). */
final class ClosureGetCurrentVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'closure_get_current.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/closure_get_current.phpt',
            'closure_get_current.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
