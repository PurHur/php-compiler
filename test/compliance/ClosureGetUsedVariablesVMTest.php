<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for Closure::getUsedVariables() (#6067, #16735). */
final class ClosureGetUsedVariablesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        if (!CompilerVersion::supportsClosureGetUsedVariables()) {
            yield 'closure_get_used_variables_phantom.phpt' => self::parsePHPT(
                __DIR__.'/cases/stdlib/closure_get_used_variables_phantom.phpt',
                'closure_get_used_variables_phantom.phpt'
            );

            return;
        }
        yield 'closure_get_used_variables.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/closure_get_used_variables.phpt',
            'closure_get_used_variables.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
