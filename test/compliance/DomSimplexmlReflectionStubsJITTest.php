<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DOM/SimpleXML Reflection stubs + children named args (#31887). */
final class DomSimplexmlReflectionStubsJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_simplexml_reflection_stubs.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_simplexml_reflection_stubs.phpt',
            'dom_simplexml_reflection_stubs.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
