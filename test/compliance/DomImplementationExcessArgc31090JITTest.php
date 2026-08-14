<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DOMImplementation excess argc → ArgumentCountError (#31090). */
final class DomImplementationExcessArgc31090JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_implementation_31090_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/excess_argc_dom_implementation_31090_jit.phpt',
            'excess_argc_dom_implementation_31090_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
