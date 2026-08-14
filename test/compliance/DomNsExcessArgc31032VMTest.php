<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOM NS excess argc → ArgumentCountError (#31032). */
final class DomNsExcessArgc31032VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_ns_31032.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dom_ns_31032.phpt',
            'excess_argc_dom_ns_31032.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
