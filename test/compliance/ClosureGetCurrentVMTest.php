<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for Closure::getCurrent() (#13981, #14504, #22583, #28710). */
final class ClosureGetCurrentVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        // Forward/phantom cases set PHP_COMPILER_PROFILE via --ENV--; always include (#22583).
        yield 'closure_fwd_apis_phantom_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_fwd_apis_phantom_84.phpt',
            'closure_fwd_apis_phantom_84.phpt'
        );
        yield 'closure_get_current_method_exists_85.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_get_current_method_exists_85.phpt',
            'closure_get_current_method_exists_85.phpt'
        );
        yield 'closure_get_current_forward_85.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_get_current_forward_85.phpt',
            'closure_get_current_forward_85.phpt'
        );
        yield 'closure_get_current_nested_85.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_get_current_nested_85.phpt',
            'closure_get_current_nested_85.phpt'
        );
        yield 'closure_get_current_reflection_return_85.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_get_current_reflection_return_85.phpt',
            'closure_get_current_reflection_return_85.phpt'
        );

        if (!CompilerVersion::supportsClosureGetCurrent()) {
            yield 'closure_get_current_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/closure_get_current_phantom.phpt',
                'closure_get_current_phantom.phpt'
            );

            return;
        }
        yield 'closure_get_current.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_get_current.phpt',
            'closure_get_current.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
