<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: get_resource_type() excess argc → ArgumentCountError (#30707). */
final class GetResourceTypeExcessArgc30707JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_get_resource_type_30707_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_get_resource_type_30707_jit.phpt',
            'excess_argc_get_resource_type_30707_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
