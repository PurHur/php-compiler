<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for html_entity_decode(). */
final class HtmlEntityDecodeJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'html_entity_decode_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/html_entity_decode_jit.phpt',
            'html_entity_decode_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
