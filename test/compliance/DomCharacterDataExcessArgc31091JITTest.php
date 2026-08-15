<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DOMCharacterData mutator excess argc → ArgumentCountError (#31091). */
final class DomCharacterDataExcessArgc31091JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_characterdata_31091_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/excess_argc_dom_characterdata_31091_jit.phpt',
            'excess_argc_dom_characterdata_31091_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
