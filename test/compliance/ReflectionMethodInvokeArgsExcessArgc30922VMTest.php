<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionMethod::invokeArgs() excess argc (#30922). */
final class ReflectionMethodInvokeArgsExcessArgc30922VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_method_invokeargs_30922.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_method_invokeargs_30922.phpt',
            'excess_argc_reflection_method_invokeargs_30922.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
