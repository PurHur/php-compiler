<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for Closure::getCurrent() (#13981, #14504, #16989). */
final class ClosureGetCurrentVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        if (!CompilerVersion::supportsClosureGetCurrent()) {
            yield 'closure_get_current_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/closure_get_current_phantom.phpt',
                'closure_get_current_phantom.phpt'
            );
            yield 'closure_get_current_forward_84.phpt' => self::parsePHPT(
                __DIR__.'/cases/language/closure_get_current_forward_84.phpt',
                'closure_get_current_forward_84.phpt'
            );

            return;
        }
        yield 'closure_get_current.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_get_current.phpt',
            'closure_get_current.phpt'
        );
        yield 'closure_get_current_forward_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_get_current_forward_84.phpt',
            'closure_get_current_forward_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
