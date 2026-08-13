<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: get_resource_type() excess argc → ArgumentCountError (#30707). */
final class GetResourceTypeExcessArgc30707VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_get_resource_type_30707.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_get_resource_type_30707.phpt',
            'excess_argc_get_resource_type_30707.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
