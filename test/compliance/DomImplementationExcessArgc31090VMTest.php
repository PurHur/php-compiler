<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMImplementation excess argc → ArgumentCountError (#31090). */
final class DomImplementationExcessArgc31090VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_implementation_31090.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/excess_argc_dom_implementation_31090.phpt',
            'excess_argc_dom_implementation_31090.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
